<?php
require_once '../auth/session-check.php';
// Allow evaluators and leaders (president/vice_president) to access evaluation
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';
require_once '../controllers/EvaluationController.php';

$database = new Database();
$db = $database->getConnection();

$teacher = new Teacher($db);
$evaluation = new Evaluation($db);

// Presidents and Vice Presidents can evaluate across all departments
if(in_array($_SESSION['role'], ['president', 'vice_president'])) {
    $teachers = $teacher->getAllTeachers('active');
} elseif (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    // Coordinators should only see teachers assigned to them by their supervisor
    $assigned_query = "SELECT t.* FROM teachers t JOIN teacher_assignments ta ON ta.teacher_id = t.id WHERE ta.evaluator_id = :evaluator_id AND t.status = 'active' ORDER BY t.name";
    $stmt = $db->prepare($assigned_query);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $stmt->execute();
    $teachers = $stmt; // mimic PDOStatement for compatibility with view loop
} else {
    $teachers = $teacher->getActiveByDepartment($_SESSION['department']);
}

// Handle form submission
if($_POST && isset($_POST['submit_evaluation'])) {
    $evalController = new EvaluationController($db);
    $result = $evalController->submitEvaluation($_POST, $_SESSION['user_id']);

    if($result['success']) {
        $_SESSION['success'] = "Evaluation submitted successfully!";
        // Go back to evaluator dashboard explicitly
        header("Location: dashboard.php");
        exit();
    } else {
        $_SESSION['error'] = "Error submitting evaluation: " . $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classroom Evaluation - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Classroom Evaluation</h3>
            </div>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Teacher Selection -->
            <div class="card mb-4" id="teacherSelection">
                <div class="card-header">
                    <h5 class="mb-0">Select Teacher to Evaluate</h5>
                </div>
                <div class="card-body">
                    <?php if($teachers->rowCount() > 0): ?>
                    <div class="list-group" id="teacherList">
                        <?php while($teacher_row = $teachers->fetch(PDO::FETCH_ASSOC)): ?>
                        <div class="list-group-item teacher-item" data-teacher-id="<?php echo $teacher_row['id']; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($teacher_row['name']); ?></h6>
                                    <p class="mb-0 text-muted"><?php echo htmlspecialchars($teacher_row['department']); ?></p>
                                </div>
                                <div>
                                    <span class="badge bg-success p-2">Evaluate this teacher</span>
                                    <i class="fas fa-chevron-right ms-2"></i>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Active Teachers</h5>
                        <p class="text-muted">There are no active teachers in your department to evaluate.</p>
                        <a href="teachers.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Manage Teachers
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            

            <!-- Evaluation Form -->
            <div id="evaluationFormContainer" class="d-none">
                <!-- Form Code Box (matches PDF export style) -->
               
                <form id="evaluationForm" method="POST">
                    <input type="hidden" id="draft_evaluation_id" name="evaluation_id" value="">
                    <input type="hidden" name="teacher_id" id="selected_teacher_id">
                    <div class="card">
                        <div class="card-header">
                                <h5 class="mb-0 text-center">CLASSROOM EVALUATION FORM</h5>
                            <div class="row">
                                <div class="col-12 text-start">
                                    <a href="evaluation.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <!-- PART 1: Faculty Information -->
                            <div class="evaluation-section">
                                <h5>PART 1: Faculty Information</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Name of Faculty:</label>
                                        <input type="text" class="form-control" id="facultyName" name="faculty_name">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Academic Year:</label>
                                        <input type="text" class="form-control" id="academicYear" name="academic_year" value="2023-2024" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Semester:</label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="semester" id="semester1" value="1st" checked required>
                                                <label class="form-check-label" for="semester1">1st</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="semester" id="semester2" value="2nd">
                                                <label class="form-check-label" for="semester2">2nd</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Department:</label>
                                        <input type="text" class="form-control" id="department" name="department">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Subject/Time of Observation:</label>
                                        <input type="text" class="form-control" id="subjectTime" name="subject_observed" placeholder="e.g., Mathematics 9:00-10:30 AM" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date of Observation:</label>
                                        <input type="date" class="form-control" id="observationDate" name="observation_date" required>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Type of Classroom Observation:</label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="observation_type" id="formal" value="Formal" checked required>
                                                <label class="form-check-label" for="formal">Formal</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="observation_type" id="informal" value="Informal">
                                                <label class="form-check-label" for="informal">Informal</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- PART 2: Mandatory Requirements -->
                            <div class="evaluation-section">
                                <h5>PART 2: Mandatory Requirements for Teachers</h5>
                                <p>Check if presented to the observer.</p>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="seatPlan" name="seat_plan" value="1">
                                            <label class="form-check-label" for="seatPlan">Seat Plan</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="courseSyllabi" name="course_syllabi" value="1">
                                            <label class="form-check-label" for="courseSyllabi">Course Syllabi</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="others" name="others_requirements" value="1">
                                            <label class="form-check-label" for="others">Others</label>
                                            <input type="text" class="form-control mt-1" id="othersSpecify" name="others_specify" placeholder="Please specify">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Rating Scale -->
                            <div class="rating-scale">
                                <h6>Rating Scale:</h6>
                                <div class="rating-scale-item">
                                    <span>5 - Excellent</span>
                                    <span>Greatly exceeds standards</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>4 - Very Satisfactory</span>
                                    <span>More than meets standards</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>3 - Satisfactory</span>
                                    <span>Meets standards</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>2 - Below Satisfactory</span>
                                    <span>Falls below standards</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>1 - Needs Improvement</span>
                                    <span>Barely meets expectations</span>
                                </div>
                            </div>
                            
                            <!-- PART 3: Domains of Teaching Performance -->
                            <div class="evaluation-section">
                                <h5>PART 3: Domains of Teaching Performance</h5>
                                
                                <!-- Communications Competence -->
                                <div class="mb-4">
                                    <h6>Communications Competence</h6>
                                    <table class="table table-bordered evaluation-table">
                                        <thead>
                                            <tr>
                                                <th width="70%">Indicator</th>
                                                <th width="6%">5</th>
                                                <th width="6%">4</th>
                                                <th width="6%">3</th>
                                                <th width="6%">2</th>
                                                <th width="6%">1</th>
                                                <th width="10%">Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody id="communicationsCompetence">
                                            <tr>
                                                <td>Uses an audible voice that can be heard at the back of the room.</td>
                                                <td><input type="radio" name="communications0" value="5" required></td>
                                                <td><input type="radio" name="communications0" value="4"></td>
                                                <td><input type="radio" name="communications0" value="3"></td>
                                                <td><input type="radio" name="communications0" value="2"></td>
                                                <td><input type="radio" name="communications0" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment0" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Speaks fluently in the language of instruction.</td>
                                                <td><input type="radio" name="communications1" value="5" required></td>
                                                <td><input type="radio" name="communications1" value="4"></td>
                                                <td><input type="radio" name="communications1" value="3"></td>
                                                <td><input type="radio" name="communications1" value="2"></td>
                                                <td><input type="radio" name="communications1" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment1" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Facilitates a dynamic discussion.</td>
                                                <td><input type="radio" name="communications2" value="5" required></td>
                                                <td><input type="radio" name="communications2" value="4"></td>
                                                <td><input type="radio" name="communications2" value="3"></td>
                                                <td><input type="radio" name="communications2" value="2"></td>
                                                <td><input type="radio" name="communications2" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment2" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses engaging non-verbal cues (facial expression, gestures).</td>
                                                <td><input type="radio" name="communications3" value="5" required></td>
                                                <td><input type="radio" name="communications3" value="4"></td>
                                                <td><input type="radio" name="communications3" value="3"></td>
                                                <td><input type="radio" name="communications3" value="2"></td>
                                                <td><input type="radio" name="communications3" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment3" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses words & expressions suited to the level of the students.</td>
                                                <td><input type="radio" name="communications4" value="5" required></td>
                                                <td><input type="radio" name="communications4" value="4"></td>
                                                <td><input type="radio" name="communications4" value="3"></td>
                                                <td><input type="radio" name="communications4" value="2"></td>
                                                <td><input type="radio" name="communications4" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment4" placeholder="Comments"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="text-end">
                                        <strong>Average: <span id="communicationsAverage">0.0</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Management and Presentation of the Lesson -->
                                <div class="mb-4">
                                    <h6>Management and Presentation of the Lesson</h6>
                                    <table class="table table-bordered evaluation-table">
                                        <thead>
                                            <tr>
                                                <th width="70%">Indicator</th>
                                                <th width="6%">5</th>
                                                <th width="6%">4</th>
                                                <th width="6%">3</th>
                                                <th width="6%">2</th>
                                                <th width="6%">1</th>
                                                <th width="10%">Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody id="managementPresentation">
                                            <tr>
                                                <td>The TILO (Topic Intended Learning Outcomes) are clearly presented.</td>
                                                <td><input type="radio" name="management0" value="5" required></td>
                                                <td><input type="radio" name="management0" value="4"></td>
                                                <td><input type="radio" name="management0" value="3"></td>
                                                <td><input type="radio" name="management0" value="2"></td>
                                                <td><input type="radio" name="management0" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment0" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Recall and connects previous lessons to the new lessons.</td>
                                                <td><input type="radio" name="management1" value="5" required></td>
                                                <td><input type="radio" name="management1" value="4"></td>
                                                <td><input type="radio" name="management1" value="3"></td>
                                                <td><input type="radio" name="management1" value="2"></td>
                                                <td><input type="radio" name="management1" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment1" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>The topic/lesson is introduced in an interesting & engaging way.</td>
                                                <td><input type="radio" name="management2" value="5" required></td>
                                                <td><input type="radio" name="management2" value="4"></td>
                                                <td><input type="radio" name="management2" value="3"></td>
                                                <td><input type="radio" name="management2" value="2"></td>
                                                <td><input type="radio" name="management2" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment2" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses current issues, real life & local examples to enrich class discussion.</td>
                                                <td><input type="radio" name="management3" value="5" required></td>
                                                <td><input type="radio" name="management3" value="4"></td>
                                                <td><input type="radio" name="management3" value="3"></td>
                                                <td><input type="radio" name="management3" value="2"></td>
                                                <td><input type="radio" name="management3" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment3" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Focuses class discussion on key concepts of the lesson.</td>
                                                <td><input type="radio" name="management4" value="5" required></td>
                                                <td><input type="radio" name="management4" value="4"></td>
                                                <td><input type="radio" name="management4" value="3"></td>
                                                <td><input type="radio" name="management4" value="2"></td>
                                                <td><input type="radio" name="management4" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment4" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Encourages active participation among students and ask questions about the topic.</td>
                                                <td><input type="radio" name="management5" value="5" required></td>
                                                <td><input type="radio" name="management5" value="4"></td>
                                                <td><input type="radio" name="management5" value="3"></td>
                                                <td><input type="radio" name="management5" value="2"></td>
                                                <td><input type="radio" name="management5" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment5" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses current instructional strategies and resources.</td>
                                                <td><input type="radio" name="management6" value="5" required></td>
                                                <td><input type="radio" name="management6" value="4"></td>
                                                <td><input type="radio" name="management6" value="3"></td>
                                                <td><input type="radio" name="management6" value="2"></td>
                                                <td><input type="radio" name="management6" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment6" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Designs teaching aids that facilitate understanding of key concepts.</td>
                                                <td><input type="radio" name="management7" value="5" required></td>
                                                <td><input type="radio" name="management7" value="4"></td>
                                                <td><input type="radio" name="management7" value="3"></td>
                                                <td><input type="radio" name="management7" value="2"></td>
                                                <td><input type="radio" name="management7" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment7" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Adapts teaching approach in the light of student feedback and reactions.</td>
                                                <td><input type="radio" name="management8" value="5" required></td>
                                                <td><input type="radio" name="management8" value="4"></td>
                                                <td><input type="radio" name="management8" value="3"></td>
                                                <td><input type="radio" name="management8" value="2"></td>
                                                <td><input type="radio" name="management8" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment8" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Aids students using thought provoking questions (Art of Questioning).</td>
                                                <td><input type="radio" name="management9" value="5" required></td>
                                                <td><input type="radio" name="management9" value="4"></td>
                                                <td><input type="radio" name="management9" value="3"></td>
                                                <td><input type="radio" name="management9" value="2"></td>
                                                <td><input type="radio" name="management9" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment9" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Integrate the institutional core values to the lessons.</td>
                                                <td><input type="radio" name="management10" value="5" required></td>
                                                <td><input type="radio" name="management10" value="4"></td>
                                                <td><input type="radio" name="management10" value="3"></td>
                                                <td><input type="radio" name="management10" value="2"></td>
                                                <td><input type="radio" name="management10" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment10" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Conduct the lesson using the principle of SMART</td>
                                                <td><input type="radio" name="management11" value="5" required></td>
                                                <td><input type="radio" name="management11" value="4"></td>
                                                <td><input type="radio" name="management11" value="3"></td>
                                                <td><input type="radio" name="management11" value="2"></td>
                                                <td><input type="radio" name="management11" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment11" placeholder="Comments"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="text-end">
                                        <strong>Average: <span id="managementAverage">0.0</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Assessment of Students' Learning -->
                                <div class="mb-4">
                                    <h6>Assessment of Students' Learning</h6>
                                    <table class="table table-bordered evaluation-table">
                                        <thead>
                                            <tr>
                                                <th width="70%">Indicator</th>
                                                <th width="6%">5</th>
                                                <th width="6%">4</th>
                                                <th width="6%">3</th>
                                                <th width="6%">2</th>
                                                <th width="6%">1</th>
                                                <th width="10%">Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody id="assessmentLearning">
                                            <tr>
                                                <td>Monitors students' understanding on key concepts discussed.</td>
                                                <td><input type="radio" name="assessment0" value="5" required></td>
                                                <td><input type="radio" name="assessment0" value="4"></td>
                                                <td><input type="radio" name="assessment0" value="3"></td>
                                                <td><input type="radio" name="assessment0" value="2"></td>
                                                <td><input type="radio" name="assessment0" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment0" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses assessment tool that relates specific course competencies stated in the syllabus.</td>
                                                <td><input type="radio" name="assessment1" value="5" required></td>
                                                <td><input type="radio" name="assessment1" value="4"></td>
                                                <td><input type="radio" name="assessment1" value="3"></td>
                                                <td><input type="radio" name="assessment1" value="2"></td>
                                                <td><input type="radio" name="assessment1" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment1" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Design test/quarter/assignments and other assessment tasks that are corrector-based.</td>
                                                <td><input type="radio" name="assessment2" value="5" required></td>
                                                <td><input type="radio" name="assessment2" value="4"></td>
                                                <td><input type="radio" name="assessment2" value="3"></td>
                                                <td><input type="radio" name="assessment2" value="2"></td>
                                                <td><input type="radio" name="assessment2" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment2" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.</td>
                                                <td><input type="radio" name="assessment3" value="5" required></td>
                                                <td><input type="radio" name="assessment3" value="4"></td>
                                                <td><input type="radio" name="assessment3" value="3"></td>
                                                <td><input type="radio" name="assessment3" value="2"></td>
                                                <td><input type="radio" name="assessment3" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment3" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Conducts normative assessment before evaluating and grading the learner's performance outcome.</td>
                                                <td><input type="radio" name="assessment4" value="5" required></td>
                                                <td><input type="radio" name="assessment4" value="4"></td>
                                                <td><input type="radio" name="assessment4" value="3"></td>
                                                <td><input type="radio" name="assessment4" value="2"></td>
                                                <td><input type="radio" name="assessment4" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment4" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Monitors the formative assessment results and find ways to ensure learning for the learners.</td>
                                                <td><input type="radio" name="assessment5" value="5" required></td>
                                                <td><input type="radio" name="assessment5" value="4"></td>
                                                <td><input type="radio" name="assessment5" value="3"></td>
                                                <td><input type="radio" name="assessment5" value="2"></td>
                                                <td><input type="radio" name="assessment5" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment5" placeholder="Comments"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div class="text-end">
                                        <strong>Average: <span id="assessmentAverage">0.0</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Overall Rating -->
                                <div class="mb-4">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Overall Rating Interpretation</h6>
                                            <div class="rating-scale">
                                                <div class="rating-scale-item">
                                                    <span>4.6-5.0</span>
                                                    <span>Excellent</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>3.6-4.5</span>
                                                    <span>Very Satisfactory</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>2.9-3.5</span>
                                                    <span>Satisfactory</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>1.8-2.5</span>
                                                    <span>Below Satisfactory</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>1.0-1.5</span>
                                                    <span>Needs Improvement</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="text-center p-4">
                                                <h4>Total Average</h4>
                                                <div class="display-4 text-primary" id="totalAverage">0.0</div>
                                                <h5 id="ratingInterpretation">Not Rated</h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-center">
                                    <button
                                        type="button"
                                        id="generateAI"
                                        class="btn"
                                        style="color: white; background-color: #31b5c9ff;"
                                    >
                                        <i class="fas fa-magic me-2"></i> Generate AI Recommendation
                                    </button>
                                </div>

                                <div class="mt-2" id="aiDebugPanel" style="display:none; max-width: 900px; margin: 0 auto;">
                                    <div class="alert alert-info py-2 mb-0" style="font-size: 0.9rem;">
                                        <strong>AI status:</strong> <span id="aiDebugText">Idle</span>
                                    </div>
                                </div>

                                
                                <!-- Strengths and Areas for Improvement -->
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                STRENGTHS:
                                            </span>
                                            <textarea class="form-control" id="strengths" name="strengths" rows="3" placeholder="List the teacher's strengths observed during the evaluation"></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                AREAS FOR IMPROVEMENT:
                                            </span>
                                            <textarea class="form-control" id="improvementAreas" name="improvement_areas" rows="3" placeholder="List areas where the teacher can improve"></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                RECOMMENDATIONS:
                                            </span>
                                            <textarea class="form-control" id="recommendations" name="recommendations" rows="3" placeholder="Provide specific recommendations for improvement"></textarea>
                                        </div>
                                    </div>
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                    AGREEMENT:
                                                </span>
                                            <textarea class="form-control" id="agreement" name="agreement" rows="3" placeholder="State agreement or additional notes"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Agreement Section -->
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="border p-3">
                                            <h6>Rater/Observer</h6>
                                            <p class="small">I certify that this classroom evaluation represents my best judgment.</p>
                                            <div class="mb-3">
                                                <label class="form-label">Signature over printed name</label>
                                                <input type="text" class="form-control" id="raterSignature" name="rater_signature" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Date</label>
                                                <input type="date" class="form-control" id="raterDate" name="rater_date" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border p-3">
                                            <h6>Faculty</h6>
                                            <p class="small">I certify that this evaluation result has been discussed with me during the post conference/debriefing.</p>
                                            <div class="mb-3">
                                                <label class="form-label">Signature of Faculty over printed name</label>
                                                <input type="text" class="form-control" id="facultySignature" name="faculty_signature" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Date</label>
                                                <input type="date" class="form-control" id="facultyDate" name="faculty_date" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Details -->
                                <!-- New Form Code Bar (horizontal, wide, above Save Draft) -->
                                 <div style="border: 2px solid #000000ff; border-radius: 8px; padding: 16px; margin-bottom: 24px; background: #f8faff; max-width: 500px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="background: #1976d2; color: #fff; font-weight: bold; width: 48%; padding: 6px 12px; border-top-left-radius: 4px;">Form Code No.</td>
                            <td style="padding: 6px 12px; color: #222; border-top-right-radius: 4px;">: FM-DPM-SMCC-RTH-04</td>
                        </tr>
                        <tr>
                            <td style="background: #1976d2; color: #fff; font-weight: bold; padding: 6px 12px;">Issue Status</td>
                            <td style="padding: 6px 12px; color: #222;">: 02</td>
                        </tr>
                        <tr>
                            <td style="background: #1976d2; color: #fff; font-weight: bold; padding: 6px 12px;">Revision No.</td>
                            <td style="padding: 6px 12px; color: #222;">: 02</td>
                        </tr>
                        <tr>
                            <td style="background: #1976d2; color: #fff; font-weight: bold; padding: 6px 12px;">Date Effective</td>
                            <td style="padding: 6px 12px; color: #222;">: 13 September 2023</td>
                        </tr>
                        <tr>
                            <td style="background: #1976d2; color: #fff; font-weight: bold; padding: 6px 12px; border-bottom-left-radius: 4px;">Approved By</td>
                            <td style="padding: 6px 12px; color: #222; border-bottom-right-radius: 4px;">: President</td>
                        </tr>
                    </table>
                </div>
                                <!-- END New Form Code Bar -->
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="form-actions mt-4">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <button type="button" class="btn btn-secondary" id="saveDraft">
                                            <i class="fas fa-save me-2"></i> Save Draft
                                        </button>
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-success me-2" name="submit_evaluation">
                                            <i class="fas fa-check me-2"></i> Submit Evaluation
                                        </button>
                                        <button type="button" class="btn btn-primary" id="downloadPDF">
                                            <i class="fas fa-download me-2"></i> Download as PDF
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script>
        // Set current date for forms
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const observationDate = document.getElementById('observationDate');
            const raterDate = document.getElementById('raterDate');
            const facultyDate = document.getElementById('facultyDate');
            
            if (observationDate) observationDate.value = today;
            if (raterDate) raterDate.value = today;
            if (facultyDate) facultyDate.value = today;
            
            // Initialize teacher selection
            initializeTeacherSelection();

            // If a teacher_id param is provided in the URL (leaders link), auto-start evaluation
            const urlParams = new URLSearchParams(window.location.search);
            const preselectTeacher = urlParams.get('teacher_id');
            if (preselectTeacher) {
                // If the teacher list is present, try to prefill the form from that list item then start
                const preItem = document.querySelector(`.teacher-item[data-teacher-id="${preselectTeacher}"]`);
                if (preItem) {
                    const nameElem = preItem.querySelector('h6');
                    const deptElem = preItem.querySelector('p');
                    const facultyNameInput = document.getElementById('facultyName');
                    const departmentInput = document.getElementById('department');

                    if (facultyNameInput && nameElem) facultyNameInput.value = nameElem.textContent.trim();
                    if (departmentInput && deptElem) departmentInput.value = deptElem.textContent.trim();
                }
                startEvaluation(preselectTeacher);
            }
        });

        function initializeTeacherSelection() {
            // Teacher selection
            document.querySelectorAll('.teacher-item').forEach(item => {
                item.addEventListener('click', function() {
                    const teacherId = this.getAttribute('data-teacher-id');
                    // Auto-fill the form fields from the clicked item
                    const nameElem = this.querySelector('h6');
                    const deptElem = this.querySelector('p');
                    const facultyNameInput = document.getElementById('facultyName');
                    const departmentInput = document.getElementById('department');

                    if (facultyNameInput && nameElem) {
                        facultyNameInput.value = nameElem.textContent.trim();
                    }
                    if (departmentInput && deptElem) {
                        departmentInput.value = deptElem.textContent.trim();
                    }

                    startEvaluation(teacherId);
                });
            });

            // Back to teachers button (guard in case element is not present)
            const backBtn = document.getElementById('backToTeachers');
            if (backBtn) {
                backBtn.addEventListener('click', function() {
                    showTeacherSelection();
                });
            }

            // Rating change listeners
            document.addEventListener('change', function(e) {
                if (e.target && e.target.type === 'radio' && (
                    e.target.name.includes('communications') ||
                    e.target.name.includes('management') ||
                    e.target.name.includes('assessment')
                )) {
                    calculateAverages();

                    // Auto-generate disabled to prevent stacking multiple pending AI requests.
                    // Use the "Generate AI Recommendation" button instead.
                }
            });

            // Save draft button
            document.getElementById('saveDraft').addEventListener('click', function() {
                saveEvaluationDraft();
            });

            // Download PDF button
            document.getElementById('downloadPDF').addEventListener('click', function() {
                exportToPDF();
            });

            // Generate AI button
            const genBtn = document.getElementById('generateAI');
            if (genBtn) {
                genBtn.addEventListener('click', function() {
                    // Visible proof that the handler is firing
                    setAIDebugStatus('Clicked. Preparing request…', true);
                    generateAINarratives({ force: true, showAlerts: true });
                });
            }
        }

        function startEvaluation(teacherId) {
            document.getElementById('teacherSelection').classList.add('d-none');
            document.getElementById('evaluationFormContainer').classList.remove('d-none');
            document.getElementById('selected_teacher_id').value = teacherId;
            
            // Set current date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('observationDate').value = today;
            document.getElementById('raterDate').value = today;
            document.getElementById('facultyDate').value = today;
        }

        function showTeacherSelection() {
            document.getElementById('teacherSelection').classList.remove('d-none');
            document.getElementById('evaluationFormContainer').classList.add('d-none');
        }
                function calculateAverages() {
            // Communications average
            let commTotal = 0;
            let commCount = 0;
            
            for (let i = 0; i < 5; i++) {
                const selected = document.querySelector(`input[name="communications${i}"]:checked`);
                if (selected) {
                    commTotal += parseInt(selected.value);
                    commCount++;
                }
            }
            
            const commAvg = commCount > 0 ? (commTotal / commCount).toFixed(1) : '0.0';
            document.getElementById('communicationsAverage').textContent = commAvg;
            
            // Management average
            let mgmtTotal = 0;
            let mgmtCount = 0;
            
            for (let i = 0; i < 12; i++) {
                const selected = document.querySelector(`input[name="management${i}"]:checked`);
                if (selected) {
                    mgmtTotal += parseInt(selected.value);
                    mgmtCount++;
                }
            }
            
            const mgmtAvg = mgmtCount > 0 ? (mgmtTotal / mgmtCount).toFixed(1) : '0.0';
            document.getElementById('managementAverage').textContent = mgmtAvg;
            
            // Assessment average
            let assessTotal = 0;
            let assessCount = 0;
            
            for (let i = 0; i < 6; i++) {
                const selected = document.querySelector(`input[name="assessment${i}"]:checked`);
                if (selected) {
                    assessTotal += parseInt(selected.value);
                    assessCount++;
                }
            }
            
            const assessAvg = assessCount > 0 ? (assessTotal / assessCount).toFixed(1) : '0.0';
            document.getElementById('assessmentAverage').textContent = assessAvg;
            
            // Overall average
            const totalCount = commCount + mgmtCount + assessCount;
            const totalSum = commTotal + mgmtTotal + assessTotal;
            const overallAvg = totalCount > 0 ? (totalSum / totalCount).toFixed(1) : '0.0';
            
            document.getElementById('totalAverage').textContent = overallAvg;
            
            // Rating interpretation
            let interpretation = '';
            let interpretationClass = '';
            const numericAvg = parseFloat(overallAvg);
            
            if (numericAvg >= 4.6) {
                interpretation = 'Excellent';
                interpretationClass = 'text-success';
            } else if (numericAvg >= 3.6) {
                interpretation = 'Very Satisfactory';
                interpretationClass = 'text-primary';
            } else if (numericAvg >= 2.9) {
                interpretation = 'Satisfactory';
                interpretationClass = 'text-info';
            } else if (numericAvg >= 1.8) {
                interpretation = 'Below Satisfactory';
                interpretationClass = 'text-warning';
            } else if (numericAvg >= 1.0) {
                interpretation = 'Needs Improvement';
                interpretationClass = 'text-danger';
            } else {
                interpretation = 'Not Rated';
                interpretationClass = 'text-muted';
            }
            
            const ratingElement = document.getElementById('ratingInterpretation');
            ratingElement.textContent = interpretation;
            ratingElement.className = interpretationClass;
            
            return {
                communications: parseFloat(commAvg),
                management: parseFloat(mgmtAvg),
                assessment: parseFloat(assessAvg),
                overall: parseFloat(overallAvg)
            };
        }



        function saveEvaluationDraft() {
            if (validateForm(true)) {
                if (!confirm('Save evaluation as draft? You can continue and submit later.')) return;

                const saveBtn = document.getElementById('saveDraft');
                const originalText = saveBtn.innerHTML;
                saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                saveBtn.disabled = true;

                const payload = getFormData();

                // Send POST to controller to save draft
                fetch('../controllers/EvaluationController.php?action=save_draft', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(flattenObject(payload)).toString()
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        alert('Draft saved successfully! You can continue editing or submit later.');
                        // Store returned evaluation_id for future reference (resume/edit)
                        if (data.evaluation_id) {
                            const draftInput = document.getElementById('draft_evaluation_id');
                            if (draftInput) draftInput.value = data.evaluation_id;
                        }
                    } else {
                        alert('Failed to save draft: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => {
                    console.error(err);
                    alert('Error saving draft. See console for details.');
                }).finally(() => {
                    saveBtn.innerHTML = originalText;
                    saveBtn.disabled = false;
                });
            } else {
                alert('Please complete all required ratings before saving draft.');
            }
        }

        function exportToPDF() {
                const teacherId = document.getElementById('selected_teacher_id').value;
                const teacherName = document.getElementById('facultyName').value;
                
                if (!teacherId || !teacherName) {
                    alert('Please select a teacher and complete the evaluation form first.');
                    return;
                }
                
                if (!confirm(`Generate PDF evaluation form for ${teacherName}?`)) return;

                // Use the export script for single evaluation form
                window.open(`../controllers/export.php?type=form&evaluation_id=${teacherId}&report_type=single`, '_blank');

            const pdfBtn = document.getElementById('downloadPDF');
            const originalText = pdfBtn.innerHTML;
            pdfBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating PDF...';
            pdfBtn.disabled = true;

            const data = getFormData();

            // Build a simple HTML report for the evaluation
            const container = document.createElement('div');
            container.style.padding = '20px';
            container.style.fontFamily = 'Arial, sans-serif';


                        // Form Code Box (styled)
                        const formCodeBox = document.createElement('div');
                        formCodeBox.style.display = 'inline-block';
                        formCodeBox.style.border = '1px solid #1976d2';
                        formCodeBox.style.borderLeft = '8px solid #1976d2';
                        formCodeBox.style.background = '#f8fafd';
                        formCodeBox.style.padding = '10px 18px 10px 14px';
                        formCodeBox.style.marginBottom = '18px';
                        formCodeBox.style.marginTop = '8px';
                        formCodeBox.style.fontSize = '15px';
                        formCodeBox.style.width = 'auto';
                        formCodeBox.innerHTML = `
                                <table style="border:none;border-collapse:collapse;font-size:15px;">
                                    <tr><td style="padding:2px 12px 2px 0;"><b>Form Code No.</b></td><td>: FM-DPM-SMCC-RTH-04</td></tr>
                                    <tr><td style="padding:2px 12px 2px 0;"><b>Issue Status</b></td><td>: 02</td></tr>
                                    <tr><td style="padding:2px 12px 2px 0;"><b>Revision No.</b></td><td>: 02</td></tr>
                                    <tr><td style="padding:2px 12px 2px 0;"><b>Date Effective</b></td><td>: 13 September 2023</td></tr>
                                    <tr><td style="padding:2px 12px 2px 0;"><b>Approved By</b></td><td>: President</td></tr>
                                </table>
                        `;
                        container.appendChild(formCodeBox);

                        const title = document.createElement('h2');
                        title.textContent = 'Classroom Evaluation Report';
                        container.appendChild(title);

            const meta = document.createElement('div');
            meta.innerHTML = `
                <p><strong>Teacher:</strong> ${escapeHtml(data.faculty_name || '')}</p>
                <p><strong>Department:</strong> ${escapeHtml(data.department || '')}</p>
                <p><strong>Subject/Time:</strong> ${escapeHtml(data.subject_observed || '')}</p>
                <p><strong>Date of Observation:</strong> ${escapeHtml(data.observation_date || '')}</p>
                <p><strong>Rater:</strong> ${escapeHtml(data.rater_signature || '')} &nbsp; <strong>Rater Date:</strong> ${escapeHtml(data.rater_date || '')}</p>
            `;
            container.appendChild(meta);

            const averagesDiv = document.createElement('div');
            averagesDiv.innerHTML = `
                <h4>Averages</h4>
                <p>Communications: ${data.averages.communications}</p>
                <p>Management: ${data.averages.management}</p>
                <p>Assessment: ${data.averages.assessment}</p>
                <p><strong>Overall:</strong> ${data.averages.overall}</p>
            `;
            container.appendChild(averagesDiv);

            const sections = document.createElement('div');
            sections.innerHTML = `
                <h4>Strengths</h4>
                <p>${escapeHtml(data.strengths || '')}</p>
                <h4>Areas for Improvement</h4>
                <p>${escapeHtml(data.improvement_areas || '')}</p>
                <h4>Recommendations</h4>
                <p>${escapeHtml(data.recommendations || '')}</p>
            `;
            container.appendChild(sections);

            // Add ratings tables for each category
            const addRatingsTable = (categoryName, ratingsObj) => {
                const heading = document.createElement('h4');
                heading.textContent = categoryName;
                container.appendChild(heading);

                const table = document.createElement('table');
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';
                table.innerHTML = '<thead><tr><th style="border:1px solid #ddd;padding:8px;text-align:left;">Item</th><th style="border:1px solid #ddd;padding:8px;">Rating</th><th style="border:1px solid #ddd;padding:8px;">Comment</th></tr></thead>';
                const tbody = document.createElement('tbody');

                const keys = Object.keys(ratingsObj || {});
                keys.forEach(k => {
                    const r = ratingsObj[k];
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="border:1px solid #ddd;padding:8px;">${escapeHtml((r.label || ('Item ' + k)).toString())}</td>
                        <td style="border:1px solid #ddd;padding:8px;text-align:center;">${escapeHtml(r.rating || '')}</td>
                        <td style="border:1px solid #ddd;padding:8px;">${escapeHtml(r.comment || '')}</td>
                    `;
                    tbody.appendChild(tr);
                });

                table.appendChild(tbody);
                container.appendChild(table);
            };

            // For labels we don't have descriptive text; use index as placeholder
            addRatingsTable('Communications Competence', data.ratings.communications);
            addRatingsTable('Management and Presentation', data.ratings.management);
            addRatingsTable("Assessment of Students' Learning", data.ratings.assessment);

            // Ensure html2pdf is loaded, then generate PDF
            function generate() {
                const opt = {
                    margin:       10,
                    filename:     `${(data.faculty_name || 'evaluation').replace(/\s+/g, '_')}_report.pdf`,
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2 },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                // Use html2pdf
                html2pdf().set(opt).from(container).save().then(() => {
                    pdfBtn.innerHTML = originalText;
                    pdfBtn.disabled = false;
                }).catch(err => {
                    console.error(err);
                    alert('Failed to generate PDF. See console for details.');
                    pdfBtn.innerHTML = originalText;
                    pdfBtn.disabled = false;
                });
            }

            if (typeof html2pdf === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js';
                script.onload = generate;
                script.onerror = () => {
                    alert('Failed to load PDF library. Check your internet connection.');
                    pdfBtn.innerHTML = originalText;
                    pdfBtn.disabled = false;
                };
                document.head.appendChild(script);
            } else {
                generate();
            }
        }

        // --- AI Generation (Python service via PHP proxy) ---
        function setAIDebugStatus(text, show = true) {
            const panel = document.getElementById('aiDebugPanel');
            const label = document.getElementById('aiDebugText');
            if (label) label.textContent = text;
            if (panel) panel.style.display = show ? 'block' : 'none';
        }

        function buildAIPayloadFromForm() {
            // Use existing getFormData() if available (keeps shapes consistent)
            if (typeof getFormData === 'function') {
                const data = getFormData();
                // The AI service expects: faculty_name, department, subject_observed, observation_type, averages, ratings
                // getFormData() already returns those keys in this system.
                return {
                    faculty_name: data.faculty_name || '',
                    department: data.department || '',
                    subject_observed: data.subject_observed || '',
                    observation_type: data.observation_type || '',
                    averages: data.averages || { communications: 0, management: 0, assessment: 0, overall: 0 },
                    ratings: data.ratings || {},
                    // Allows the AI service to generate shorter/standard/detailed if you add UI later
                    style: data.style || 'standard'
                };
            }

            // Fallback minimal payload (should still work via template generation)
            const averages = (typeof calculateAverages === 'function') ? calculateAverages() : { communications: 0, management: 0, assessment: 0, overall: 0 };
            return {
                faculty_name: (document.getElementById('facultyName')?.value || ''),
                department: (document.getElementById('department')?.value || ''),
                subject_observed: (document.getElementById('subjectObserved')?.value || ''),
                observation_type: (document.querySelector('input[name="observationType"]:checked')?.value || ''),
                averages,
                ratings: {}
            };
        }

        async function generateAINarratives(options = {}) {
            const { force = false, showAlerts = false } = options;

            const btn = document.getElementById('generateAI');
            const strengthsEl = document.getElementById('strengths');
            const improvementEl = document.getElementById('improvementAreas');
            const recEl = document.getElementById('recommendations');

            if (!strengthsEl || !improvementEl || !recEl) {
                if (showAlerts) alert('AI fields not found on the page.');
                return;
            }

            // Keep a stable original label so the button never gets stuck
            const defaultBtnHtml = '<i class="fas fa-magic me-2"></i> Generate AI Recommendation';
            const restoreButton = () => {
                if (!btn) return;
                btn.disabled = false;
                const prev = btn.dataset.prevText;
                btn.innerHTML = (prev && prev.trim().length) ? prev : defaultBtnHtml;
                delete btn.dataset.prevText;
            };

            // If user already has text and not forcing, do nothing
            if (!force && (
                (strengthsEl.value || '').trim() ||
                (improvementEl.value || '').trim() ||
                (recEl.value || '').trim()
            )) {
                restoreButton();
                return;
            }

            const payload = buildAIPayloadFromForm();

            if (btn) {
                btn.disabled = true;
                // Only capture original text once; don't overwrite it with "Generating..."
                if (!btn.dataset.prevText) btn.dataset.prevText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
            }
            setAIDebugStatus('Generating… first run may take a while (model load).', true);

            try {
                const res = await fetch('../controllers/ai_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                // Handle cases where PHP returns HTML (e.g., login redirect page) instead of JSON
                const contentType = (res.headers.get('content-type') || '').toLowerCase();
                const rawText = await res.text();

                let data = null;
                if (contentType.includes('application/json')) {
                    try {
                        data = JSON.parse(rawText);
                    } catch (e) {
                        // keep null
                    }
                }

                if (!res.ok || !data || data.success !== true) {
                    console.error('AI proxy error:', { status: res.status, data, rawText });
                    let msg = `AI generation failed (HTTP ${res.status}).`;
                    if (data && (data.message || data.error)) {
                        msg = (data.message || data.error);
                    } else if (rawText && rawText.toLowerCase().includes('login')) {
                        msg = 'Not authenticated. Please refresh the page and log in again.';
                    } else if (rawText && rawText.trim().length) {
                        msg = `AI proxy returned unexpected response. Check console for details.`;
                    }
                    setAIDebugStatus(msg, true);
                    if (showAlerts) alert(msg);
                    restoreButton();
                    return;
                }

                const out = data.data || {};
                strengthsEl.value = out.strengths || strengthsEl.value;
                improvementEl.value = out.improvement_areas || improvementEl.value;
                recEl.value = out.recommendations || recEl.value;
                setAIDebugStatus('Done', true);
            } catch (err) {
                console.error(err);
                const msg = 'AI generation error. Is the AI server running on 127.0.0.1:8008?';
                setAIDebugStatus(msg, true);
                if (showAlerts) alert(msg);
            } finally {
                restoreButton();
            }
        }

        function validateForm(isDraft = false) {
            let isValid = true;
            const errorFields = [];

            if (!isDraft) {
                const requiredFields = document.querySelectorAll('[required]');
                // Check required fields
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.classList.add('is-invalid');
                        errorFields.push(field.name || field.id);
                        isValid = false;
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });

                // Check if at least some ratings are provided
                const communicationsRatings = document.querySelectorAll('input[name^="communications"]:checked');
                const managementRatings = document.querySelectorAll('input[name^="management"]:checked');
                const assessmentRatings = document.querySelectorAll('input[name^="assessment"]:checked');

                if (communicationsRatings.length === 0 && managementRatings.length === 0 && assessmentRatings.length === 0) {
                    alert('Please provide ratings for at least one evaluation category.');
                    isValid = false;
                }
            } else {
                // Draft mode: be permissive — require at least some meaningful content (one rating or some text)
                const communicationsRatings = document.querySelectorAll('input[name^="communications"]:checked');
                const managementRatings = document.querySelectorAll('input[name^="management"]:checked');
                const assessmentRatings = document.querySelectorAll('input[name^="assessment"]:checked');
                const strengths = document.getElementById('strengths').value.trim();
                const improvements = document.getElementById('improvementAreas').value.trim();
                const recommendations = document.getElementById('recommendations').value.trim();
                const facultyName = document.getElementById('facultyName').value.trim();

                if (communicationsRatings.length === 0 && managementRatings.length === 0 && assessmentRatings.length === 0 && !strengths && !improvements && !recommendations && !facultyName) {
                    alert('Please provide at least one rating or some notes before saving a draft.');
                    isValid = false;
                }
            }
            
            // Check ratings completeness (for submission)
            if (!isDraft) {
                const categories = ['communications', 'management', 'assessment'];
                const expectedCounts = { communications: 5, management: 12, assessment: 6 };
                
                for (const category of categories) {
                    const ratings = document.querySelectorAll(`input[name^="${category}"]:checked`);
                    if (ratings.length > 0 && ratings.length < expectedCounts[category]) {
                        if (confirm(`You have only completed ${ratings.length} out of ${expectedCounts[category]} items in ${category.replace('communications', 'Communications').replace('management', 'Management').replace('assessment', 'Assessment')}. Continue anyway?`)) {
                            // User chose to continue with incomplete category
                        } else {
                            isValid = false;
                            break;
                        }
                    }
                }
            }
            
            if (!isValid && !isDraft) {
                alert('Please complete all required fields before submitting.');
                // Scroll to first error
                if (errorFields.length > 0) {
                    const firstError = document.querySelector('.is-invalid');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstError.focus();
                    }
                }
            }
            
            return isValid;
        }

        function getFormData() {
            const formData = {
                teacher_id: document.getElementById('selected_teacher_id').value,
                faculty_name: document.getElementById('facultyName').value,
                academic_year: document.getElementById('academicYear').value,
                semester: document.querySelector('input[name="semester"]:checked')?.value,
                department: document.getElementById('department').value,
                subject_observed: document.getElementById('subjectTime').value,
                observation_date: document.getElementById('observationDate').value,
                observation_type: document.querySelector('input[name="observation_type"]:checked')?.value,
                seat_plan: document.getElementById('seatPlan').checked ? 1 : 0,
                course_syllabi: document.getElementById('courseSyllabi').checked ? 1 : 0,
                others_requirements: document.getElementById('others').checked ? 1 : 0,
                others_specify: document.getElementById('othersSpecify').value,
                strengths: document.getElementById('strengths').value,
                improvement_areas: document.getElementById('improvementAreas').value,
                recommendations: document.getElementById('recommendations').value,
                rater_signature: document.getElementById('raterSignature').value,
                rater_date: document.getElementById('raterDate').value,
                faculty_signature: document.getElementById('facultySignature').value,
                faculty_date: document.getElementById('facultyDate').value,
                ratings: {}
            };
            
            // Collect all ratings
            ['communications', 'management', 'assessment'].forEach(category => {
                formData.ratings[category] = {};
                const count = category === 'communications' ? 5 : category === 'management' ? 12 : 6;
                
                for (let i = 0; i < count; i++) {
                    const rating = document.querySelector(`input[name="${category}${i}"]:checked`);
                    // Comment inputs in the HTML are named like "communications_comment0"
                    // (no underscore between category and comment), so match that here.
                    const comment = document.querySelector(`input[name="${category}_comment${i}"]`) ||
                                    document.querySelector(`textarea[name="${category}_comment${i}"]`) ||
                                    document.querySelector(`input[name="${category}_comment${i}"]`) ||
                                    document.querySelector(`textarea[name="${category}_comment${i}"]`);
                    
                    if (rating) {
                        formData.ratings[category][i] = {
                            rating: rating.value,
                            comment: comment ? comment.value : ''
                        };

                        // Also include flat keys because the PHP backend currently expects
                        // POST fields like communications0, communications_comment0, etc.
                        formData[`${category}${i}`] = rating.value;
                        formData[`${category}_comment${i}`] = comment ? comment.value : '';
                    }
                }
            });
            
            // Add calculated averages
            const averages = calculateAverages();
            formData.averages = averages;
            
            return formData;
        }

        // Escape HTML for safe insertion into generated report
        function escapeHtml(str) {
            if (!str && str !== 0) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Flatten nested objects to key/value pairs for form POST
        function flattenObject(obj, prefix = '') {
            const pairs = {};

            for (const key in obj) {
                if (!Object.prototype.hasOwnProperty.call(obj, key)) continue;
                const value = obj[key];
                const pref = prefix ? `${prefix}[${key}]` : key;

                if (value === null || value === undefined) {
                    pairs[pref] = '';
                } else if (typeof value === 'object' && !(value instanceof Date)) {
                    const nested = flattenObject(value, pref);
                    for (const nKey in nested) {
                        if (Object.prototype.hasOwnProperty.call(nested, nKey)) {
                            pairs[nKey] = nested[nKey];
                        }
                    }
                } else {
                    pairs[pref] = value;
                }
            }

            return pairs;
        }

        // Final submit handler (AJAX)
        // We submit via AJAX so we can redirect cleanly back to the dashboard
        // and immediately see the new row in "Recent Evaluations".
        const evaluationForm = document.getElementById('evaluationForm');
        if (evaluationForm) {
            evaluationForm.addEventListener('submit', function(e) {
                e.preventDefault();

                if (!validateForm()) {
                    return false;
                }

                submitEvaluationFinal();
                return false;
            });
        }

        // Auto-save functionality (optional)
        let autoSaveTimeout;
        function setupAutoSave() {
            const inputs = document.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(() => {
                        if (validateForm(true)) {
                            console.log('Auto-saving draft...');
                            // In real implementation, call saveEvaluationDraft() or make AJAX call
                        }
                    }, 3000); // Save 3 seconds after last change
                });
            });
        }

        // Initialize when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Set current date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('observationDate').value = today;
            document.getElementById('raterDate').value = today;
            document.getElementById('facultyDate').value = today;

            initializeTeacherSelection();
            setupTeacherSearch();
        });

        function setupTeacherSearch() {
            const teacherSearch = document.createElement('div');
            teacherSearch.className = 'mb-3';
            teacherSearch.innerHTML = `
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" id="teacherSearch" placeholder="Search teachers...">
                </div>
            `;
            
            const teacherList = document.getElementById('teacherList');
            if (teacherList) {
                teacherList.parentNode.insertBefore(teacherSearch, teacherList);
                
                const searchInput = document.getElementById('teacherSearch');
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const teacherItems = document.querySelectorAll('.teacher-item');
                    
                    teacherItems.forEach(item => {
                        const teacherName = item.querySelector('h6').textContent.toLowerCase();
                        if (teacherName.includes(searchTerm)) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        }
                async function submitEvaluationFinal() {
                        const btn = document.querySelector('button[name="submit_evaluation"]');
                        if (!btn) return;

                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
                        btn.disabled = true;

                        try {
                                const payload = getFormData();
                                if (!payload.teacher_id) {
                                        alert('Please select a teacher.');
                                        return;
                                }

                                const res = await fetch('../controllers/EvaluationController.php?action=submit_evaluation', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: new URLSearchParams(flattenObject(payload)).toString()
                                });

                                const json = await res.json().catch(() => null);
                                if (!json || !json.success) {
                                        const msg = (json && json.message) ? json.message : ('Submit failed (HTTP ' + res.status + ')');
                                        throw new Error(msg);
                                }

                                alert('Evaluation submitted successfully!');
                                window.location.href = '../evaluators/dashboard.php';
                        } catch (err) {
                                console.error(err);
                                alert(err.message || 'Submit failed. See console for details.');
                        } finally {
                                btn.innerHTML = originalText;
                                btn.disabled = false;
                        }
                }
    </script>
</body>
</html>