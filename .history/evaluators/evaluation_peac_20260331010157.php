<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';
require_once '../controllers/EvaluationController.php';

$database = new Database();
$db = $database->getConnection();

// Get teacher_id from URL
$teacher_id = $_GET['teacher_id'] ?? null;
if (empty($teacher_id)) {
    header("Location: evaluation.php");
    exit();
}

// Fetch teacher info
$t_stmt = $db->prepare("SELECT * FROM teachers WHERE id = :id AND status = 'active' LIMIT 1");
$t_stmt->bindValue(':id', $teacher_id);
$t_stmt->execute();
$teacher_data = $t_stmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: evaluation.php");
    exit();
}

// Verify the schedule is set and form type is PEAC
$scheduleRaw = $teacher_data['evaluation_schedule'] ?? null;
$formType = $teacher_data['evaluation_form_type'] ?? 'iso';

if ($formType !== 'peac' && $formType !== 'both') {
    header("Location: evaluation.php?teacher_id=" . urlencode($teacher_id));
    exit();
}

// Schedule validation
$can_evaluate = false;
$schedule_message = '';
if (!empty($scheduleRaw)) {
    $schedTs = strtotime($scheduleRaw);
    $now = time();
    if ($schedTs !== false && $now >= $schedTs) {
        $can_evaluate = true;
    } else {
        $schedule_message = "Scheduled for " . date('M d, Y g:i A', $schedTs) . ". You can evaluate at or after this time.";
    }
}

$evaluatorRole = $_SESSION['role'] ?? '';
if (in_array($evaluatorRole, ['president', 'vice_president'])) {
    $can_evaluate = true;
}

if (!$can_evaluate && empty($scheduleRaw)) {
    $schedule_message = "No schedule set for this teacher.";
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
$dept_display = $department_map[$teacher_data['department']] ?? $teacher_data['department'];

$m = (int)date('n'); $y = (int)date('Y');
$currentAY = ($m >= 6) ? "$y-" . ($y+1) : ($y-1) . "-$y";
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEAC Classroom Observation Form</title>
    <?php include '../includes/header.php'; ?>
    <style>
        /* Reset global evaluation-table overrides from style.css for PEAC's different column layout */
        .evaluation-table {
            table-layout: auto;
        }
        .evaluation-table th:first-child,
        .evaluation-table td:first-child {
            width: auto;
            max-width: none;
        }
        .evaluation-table th:nth-child(n+2):nth-child(-n+6),
        .evaluation-table td:nth-child(n+2):nth-child(-n+6) {
            width: auto;
            padding: revert;
        }
        .evaluation-table td:nth-child(n+2):nth-child(-n+6) input[type="radio"] {
            transform: none;
        }
        .evaluation-table th:last-child,
        .evaluation-table td:last-child {
            width: auto;
            min-width: auto;
        }
        .evaluation-table th, .evaluation-table td {
            text-align: center;
            vertical-align: middle;
        }
        .evaluation-table td:nth-child(1) {
            text-align: center;
            font-weight: 600;
        }
        .evaluation-table td:nth-child(2) {
            text-align: left;
        }
        .evaluation-table input[type="radio"] {
            cursor: pointer;
        }
        .signature-canvas {
            width: 100%;
            max-width: 420px;
            height: 200px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background: #fff;
            touch-action: none;
            cursor: crosshair;
        }
        .peac-header {
            text-align: center;
            line-height: 1.3;
            margin-bottom: 20px;
            display: none;
        }
        @media print {
            .peac-header { display: block !important; }
        }
        .rating-scale-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .rating-scale-box .rs-item {
            display: flex;
            gap: 10px;
        }
        .rating-scale-box .rs-item span:first-child {
            font-weight: 700;
            min-width: 18px;
        }
        .section-header {
            background: #2c3e50;
            color: white;
            padding: 10px 14px;
            font-weight: 600;
            border-radius: 4px 4px 0 0;
            margin-top: 24px;
        }
        .ai-suggestion-wrap { margin-top: 10px; }
        .ai-suggestion-label { font-size: 0.9rem; color: #6c757d; margin-bottom: 8px; }
        .ai-suggestion-list { display: flex; flex-direction: column; gap: 8px; }
        .ai-option-card {
            background: #f0f7ff; border: 1px solid #b8d4f0; border-radius: 20px;
            padding: 12px 20px; margin-bottom: 8px; cursor: pointer;
            text-align: center; font-size: 13px; line-height: 1.55; color: #333;
            transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;
        }
        .ai-option-card:hover {
            background: #dceefb; border-color: #7ab3e0; box-shadow: 0 2px 6px rgba(0,0,0,0.08);
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)
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

            <?php if (!$can_evaluate): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($schedule_message); ?>
                <a href="evaluation.php" class="btn btn-sm btn-secondary ms-2">Back to Teacher List</a>
            </div>
            <?php else: ?>

            <form id="peacEvaluationForm">
                <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
                <input type="hidden" name="evaluation_form_type" value="peac">

                <div class="card">
                    <div class="card-header">
                        <div class="row mb-2 no-print">
                            <div class="col-12 text-start">
                                <a href="evaluation.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Back
                                </a>
                            </div>
                        </div>
                        <h5 class="mb-0 text-center no-print">PEAC Classroom Observation Form</h5>
                        <div class="peac-header">
                            <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC" style="max-width: 70px; height:auto;" class="mb-2"><br>
                            <strong>Saint Michael College of Caraga</strong><br>
                            <small>Nasipit, Agusan del Norte</small><br>
                            <small>Brgy. 4, Agusan del Norte, Caraga Region</small><br>
                            <small>Tel. Nos. (085) 343-3251, (085) 283-3113</small><br>
                            <strong class="text-primary"><?php echo htmlspecialchars($dept_display); ?></strong>
                            <h5 class="mt-3 fw-bold">CLASSROOM OBSERVATION FORM</h5>
                        </div>
                    </div>

                    <div class="card-body">

                        <!-- Faculty Information -->
                        <div class="mb-3" style="line-height:1.8;">
                            <div>Name of School: <strong>Saint Michael College of Caraga</strong></div>
                            <div>Region: <strong>Caraga Region</strong></div>
                            <div>Address: <strong>Atupan St. Brgy. 4, Poblacion, Nasipit, Agusan del Norte</strong></div>
                            <div class="d-flex flex-wrap gap-4">
                                <div>Name of Teacher: <strong><u><?php echo htmlspecialchars($teacher_data['name']); ?></u></strong>
                                    <input type="hidden" id="facultyName" name="faculty_name" value="<?php echo htmlspecialchars($teacher_data['name']); ?>">
                                </div>
                                <div>Grade Level/Section: <strong><u><?php echo htmlspecialchars($teacher_data['evaluation_subject_area'] ?? ''); ?></u></strong>
                                    <input type="hidden" name="grade_level_section" value="<?php echo htmlspecialchars($teacher_data['evaluation_subject_area'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-4">
                                <div>Subject of Instruction: <strong><u><?php echo htmlspecialchars($teacher_data['evaluation_subject'] ?? ''); ?></u></strong>
                                    <input type="hidden" id="subjectObserved" name="subject_observed" value="<?php echo htmlspecialchars($teacher_data['evaluation_subject'] ?? ''); ?>">
                                </div>
                                <div>Date of Observation: <strong><u><?php echo date('F d, Y'); ?></u></strong>
                                    <input type="hidden" id="observationDate" name="observation_date" value="<?php echo $today; ?>">
                                </div>
                            </div>
                            <div>Name of Observer: <strong><u><?php echo htmlspecialchars($_SESSION['name']); ?></u></strong></div>
                            <input type="hidden" name="academic_year" value="<?php echo $currentAY; ?>">
                            <input type="hidden" name="semester" value="<?php echo htmlspecialchars($teacher_data['evaluation_semester'] ?? '1st'); ?>">
                            <input type="hidden" name="department" value="<?php echo htmlspecialchars($teacher_data['department']); ?>">
                            <input type="hidden" name="observation_type" value="Formal">
                            <input type="hidden" name="observation_room" value="<?php echo htmlspecialchars($teacher_data['evaluation_room'] ?? ''); ?>">
                            <input type="hidden" name="subject_area" value="<?php echo htmlspecialchars($teacher_data['evaluation_subject_area'] ?? ''); ?>">
                            <input type="hidden" name="evaluation_focus" value="<?php echo htmlspecialchars($teacher_data['evaluation_focus'] ?? ''); ?>">
                        </div>

                        <!-- Rating Scale -->
                        <div class="rating-scale-box">
                            <h6 class="fw-bold mb-2">RATING SCALE:</h6>
                            <div class="rs-item"><span>4</span> <span>- Performance of this item is innovatively done.</span></div>
                            <div class="rs-item"><span>3</span> <span>- Performance of this item is satisfactorily done.</span></div>
                            <div class="rs-item"><span>2</span> <span>- Performance of this item is partially done due to some omissions.</span></div>
                            <div class="rs-item"><span>1</span> <span>- Performance of this item is partially done due to serious errors and misconceptions.</span></div>
                            <div class="rs-item"><span>0</span> <span>- Performance of this item is not observed at all.</span></div>
                        </div>

                        <!-- A. TEACHER ACTIONS -->
                        <div class="section-header">A. TEACHER ACTIONS</div>
                        <div class="table-responsive">
                            <table class="table table-bordered evaluation-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:3%">#</th>
                                        <th style="width:57%">Indicator</th>
                                        <th style="width:8%">4</th>
                                        <th style="width:8%">3</th>
                                        <th style="width:8%">2</th>
                                        <th style="width:8%">1</th>
                                        <th style="width:8%">0</th>
                                    </tr>
                                </thead>
                                <tbody id="teacherActions">
                                    <tr>
                                        <td>1.</td>
                                        <td>The teacher communicates clear expectations of student performance in line with the unit standards and competencies.</td>
                                        <td><input type="radio" name="teacher_action0" value="4" required></td>
                                        <td><input type="radio" name="teacher_action0" value="3"></td>
                                        <td><input type="radio" name="teacher_action0" value="2"></td>
                                        <td><input type="radio" name="teacher_action0" value="1"></td>
                                        <td><input type="radio" name="teacher_action0" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>2.</td>
                                        <td>The teacher utilizes various learning materials, resources and strategies to enable all students to learn and achieve the unit standards and competencies and learning goals.</td>
                                        <td><input type="radio" name="teacher_action1" value="4" required></td>
                                        <td><input type="radio" name="teacher_action1" value="3"></td>
                                        <td><input type="radio" name="teacher_action1" value="2"></td>
                                        <td><input type="radio" name="teacher_action1" value="1"></td>
                                        <td><input type="radio" name="teacher_action1" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>3.</td>
                                        <td>The teacher monitors and checks on students' learning and attainment of the unit standards and competencies by conducting varied forms of assessments during class discussion.</td>
                                        <td><input type="radio" name="teacher_action2" value="4" required></td>
                                        <td><input type="radio" name="teacher_action2" value="3"></td>
                                        <td><input type="radio" name="teacher_action2" value="2"></td>
                                        <td><input type="radio" name="teacher_action2" value="1"></td>
                                        <td><input type="radio" name="teacher_action2" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>4.</td>
                                        <td>The teacher provides appropriate feedback or interventions to enable students in attaining the unit standards and competencies.</td>
                                        <td><input type="radio" name="teacher_action3" value="4" required></td>
                                        <td><input type="radio" name="teacher_action3" value="3"></td>
                                        <td><input type="radio" name="teacher_action3" value="2"></td>
                                        <td><input type="radio" name="teacher_action3" value="1"></td>
                                        <td><input type="radio" name="teacher_action3" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>5.</td>
                                        <td>The teacher manages the classroom environment and time in a way that supports student learning and the achievement of the unit standards and competencies.</td>
                                        <td><input type="radio" name="teacher_action4" value="4" required></td>
                                        <td><input type="radio" name="teacher_action4" value="3"></td>
                                        <td><input type="radio" name="teacher_action4" value="2"></td>
                                        <td><input type="radio" name="teacher_action4" value="1"></td>
                                        <td><input type="radio" name="teacher_action4" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>6.</td>
                                        <td>The teacher processes students' understanding by asking clarifying or critical thinking questions related to the unit standards and competencies.</td>
                                        <td><input type="radio" name="teacher_action5" value="4" required></td>
                                        <td><input type="radio" name="teacher_action5" value="3"></td>
                                        <td><input type="radio" name="teacher_action5" value="2"></td>
                                        <td><input type="radio" name="teacher_action5" value="1"></td>
                                        <td><input type="radio" name="teacher_action5" value="0"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- B. STUDENT LEARNING ACTIONS -->
                        <div class="section-header">B. STUDENT LEARNING ACTIONS</div>
                        <div class="table-responsive">
                            <table class="table table-bordered evaluation-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:3%">#</th>
                                        <th style="width:57%">Indicator</th>
                                        <th style="width:8%">4</th>
                                        <th style="width:8%">3</th>
                                        <th style="width:8%">2</th>
                                        <th style="width:8%">1</th>
                                        <th style="width:8%">0</th>
                                    </tr>
                                </thead>
                                <tbody id="studentActions">
                                    <tr>
                                        <td>7.</td>
                                        <td>The students are active and engaged with the different learning tasks aimed at accomplishing the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action0" value="4" required></td>
                                        <td><input type="radio" name="student_action0" value="3"></td>
                                        <td><input type="radio" name="student_action0" value="2"></td>
                                        <td><input type="radio" name="student_action0" value="1"></td>
                                        <td><input type="radio" name="student_action0" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>8.</td>
                                        <td>The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action1" value="4" required></td>
                                        <td><input type="radio" name="student_action1" value="3"></td>
                                        <td><input type="radio" name="student_action1" value="2"></td>
                                        <td><input type="radio" name="student_action1" value="1"></td>
                                        <td><input type="radio" name="student_action1" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>9.</td>
                                        <td>The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action2" value="4" required></td>
                                        <td><input type="radio" name="student_action2" value="3"></td>
                                        <td><input type="radio" name="student_action2" value="2"></td>
                                        <td><input type="radio" name="student_action2" value="1"></td>
                                        <td><input type="radio" name="student_action2" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>10.</td>
                                        <td>The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action3" value="4" required></td>
                                        <td><input type="radio" name="student_action3" value="3"></td>
                                        <td><input type="radio" name="student_action3" value="2"></td>
                                        <td><input type="radio" name="student_action3" value="1"></td>
                                        <td><input type="radio" name="student_action3" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>11.</td>
                                        <td>The students are able to explain how their ideas, outputs or performances accomplish the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action4" value="4" required></td>
                                        <td><input type="radio" name="student_action4" value="3"></td>
                                        <td><input type="radio" name="student_action4" value="2"></td>
                                        <td><input type="radio" name="student_action4" value="1"></td>
                                        <td><input type="radio" name="student_action4" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>12.</td>
                                        <td>The students, when encouraged or on their own, ask questions to clarify or deepen their understanding of the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action5" value="4" required></td>
                                        <td><input type="radio" name="student_action5" value="3"></td>
                                        <td><input type="radio" name="student_action5" value="2"></td>
                                        <td><input type="radio" name="student_action5" value="1"></td>
                                        <td><input type="radio" name="student_action5" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>13.</td>
                                        <td>The students are able to relate or transfer their learning to daily life and real world situations.</td>
                                        <td><input type="radio" name="student_action6" value="4" required></td>
                                        <td><input type="radio" name="student_action6" value="3"></td>
                                        <td><input type="radio" name="student_action6" value="2"></td>
                                        <td><input type="radio" name="student_action6" value="1"></td>
                                        <td><input type="radio" name="student_action6" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>14.</td>
                                        <td>The students are able to integrate 21st century skills in their achievement of the unit standards and competencies.</td>
                                        <td><input type="radio" name="student_action7" value="4" required></td>
                                        <td><input type="radio" name="student_action7" value="3"></td>
                                        <td><input type="radio" name="student_action7" value="2"></td>
                                        <td><input type="radio" name="student_action7" value="1"></td>
                                        <td><input type="radio" name="student_action7" value="0"></td>
                                    </tr>
                                    <tr>
                                        <td>15.</td>
                                        <td>The students are able to reflect on and connect their learning with the school's PVMGO.</td>
                                        <td><input type="radio" name="student_action8" value="4" required></td>
                                        <td><input type="radio" name="student_action8" value="3"></td>
                                        <td><input type="radio" name="student_action8" value="2"></td>
                                        <td><input type="radio" name="student_action8" value="1"></td>
                                        <td><input type="radio" name="student_action8" value="0"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Total Average -->
                        <div class="text-end mt-3 mb-3">
                            <strong>Total Average: <span id="totalAverage" class="text-primary">0.00</span></strong>
                        </div>

                        <!-- AI Generate Button -->
                        <div class="d-flex justify-content-center">
                            <button type="button" id="generateAI" class="btn" style="color: white; background-color: #31b5c9ff;">
                                <i class="fas fa-magic me-2"></i> Generate AI Recommendation
                            </button>
                        </div>
                        <div class="mt-2" id="aiDebugPanel" style="display:none; max-width: 900px; margin: 0 auto;">
                            <div class="alert alert-info py-2 mb-0" style="font-size: 0.9rem;">
                                <strong>AI status:</strong> <span id="aiDebugText">Idle</span>
                            </div>
                        </div>
                        <div class="mt-3" id="aiSuggestionPanel" style="display:none; max-width: 1100px; margin: 0 auto;"></div>

                        <!-- Strengths and Areas for Improvement -->
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="border rounded">
                                    <div class="fw-bold p-2 border-bottom" style="background:#f8f9fa;">STRENGTH/S:</div>
                                    <div class="p-2">
                                        <textarea class="form-control border-0" name="strengths" id="strengths" rows="4" placeholder="List the teacher's strengths observed" style="resize:vertical;"></textarea>
                                    </div>
                                </div>
                                <div class="mt-2 ai-category-panel" id="aiStrengthsPanel" style="display:none;">
                                    <div class="ai-suggestion-wrap"><div id="aiSuggestionStrengths" class="ai-suggestion-content small"></div></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded">
                                    <div class="fw-bold p-2 border-bottom" style="background:#f8f9fa;">AREAS FOR IMPROVEMENT:</div>
                                    <div class="p-2">
                                        <textarea class="form-control border-0" name="improvement_areas" id="improvementAreas" rows="4" placeholder="List areas where the teacher can improve" style="resize:vertical;"></textarea>
                                    </div>
                                </div>
                                <div class="mt-2 ai-category-panel" id="aiImprovementsPanel" style="display:none;">
                                    <div class="ai-suggestion-wrap"><div id="aiSuggestionImprovements" class="ai-suggestion-content small"></div></div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="border rounded">
                                    <div class="fw-bold p-2 border-bottom" style="background:#f8f9fa;">RECOMMENDATION/S:</div>
                                    <div class="p-2">
                                        <textarea class="form-control border-0" name="recommendations" id="recommendations" rows="3" placeholder="Provide specific recommendations" style="resize:vertical;"></textarea>
                                    </div>
                                </div>
                                <div class="mt-2 ai-category-panel" id="aiRecommendationsPanel" style="display:none;">
                                    <div class="ai-suggestion-wrap"><div id="aiSuggestionRecommendations" class="ai-suggestion-content small"></div></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded">
                                    <div class="fw-bold p-2 border-bottom" style="background:#f8f9fa;">AGREEMENT:</div>
                                    <div class="p-2">
                                        <textarea class="form-control border-0" name="agreement" rows="3" placeholder="State agreement or additional notes" style="resize:vertical;"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Signatures -->
                        <div class="row mt-4 align-items-stretch">
                            <div class="col-md-6 d-flex">
                                <div class="border p-3 w-100 d-flex flex-column">
                                    <h6>Rater/Observer:</h6>
                                    <p class="small" style="min-height:3em;">I certify that this classroom evaluation represents my best judgment.</p>
                                    <div class="mb-3">
                                        <label class="form-label">Signature over printed name</label>
                                        <input type="hidden" id="raterSignature" name="rater_signature" required>
                                        <img id="raterSignaturePreview" alt="Rater signature" style="display:none; max-width:100%; height:60px; border:1px solid #ced4da; border-radius:4px; background:#fff;" />
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-sign="rater">Sign</button>
                                        </div>
                                        <div class="signature-canvas-wrap mt-2" data-sign-wrap="rater" style="display:none;">
                                            <canvas id="raterSignaturePad" class="signature-canvas" height="200"></canvas>
                                            <div class="d-flex gap-2 mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-sign="rater">Clear</button>
                                                <button type="button" class="btn btn-sm btn-primary" data-apply-sign="rater">Use this signature</button>
                                            </div>
                                        </div>
                                        <input type="text" class="form-control mt-2" id="raterPrintedName" name="rater_printed_name" value="<?php echo htmlspecialchars($_SESSION['name']); ?>" readonly>
                                    </div>
                                    <div class="mt-auto">
                                        <label class="form-label">Date</label>
                                        <input type="date" class="form-control" id="raterDate" name="rater_date" value="<?php echo $today; ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 d-flex">
                                <div class="border p-3 w-100 d-flex flex-column">
                                    <h6>Faculty:</h6>
                                    <p class="small" style="min-height:3em;">I certify that this evaluation result has been discussed with me during the post-conference/debriefing.</p>
                                    <div class="mb-3">
                                        <label class="form-label">Signature of Faculty over printed name</label>
                                        <input type="hidden" id="facultySignature" name="faculty_signature" required>
                                        <img id="facultySignaturePreview" alt="Faculty signature" style="display:none; max-width:100%; height:60px; border:1px solid #ced4da; border-radius:4px; background:#fff;" />
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-sign="faculty">Sign</button>
                                        </div>
                                        <div class="signature-canvas-wrap mt-2" data-sign-wrap="faculty" style="display:none;">
                                            <canvas id="facultySignaturePad" class="signature-canvas" height="200"></canvas>
                                            <div class="d-flex gap-2 mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-sign="faculty">Clear</button>
                                                <button type="button" class="btn btn-sm btn-primary" data-apply-sign="faculty">Use this signature</button>
                                            </div>
                                        </div>
                                        <input type="text" class="form-control mt-2" id="facultyPrintedName" name="faculty_printed_name" value="<?php echo htmlspecialchars($teacher_data['name']); ?>" readonly>
                                    </div>
                                    <div class="mt-auto">
                                        <label class="form-label">Date</label>
                                        <input type="date" class="form-control" id="facultyDate" name="faculty_date" value="<?php echo $today; ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PEAC Footer -->
                        <div class="mt-3 text-muted small">
                            <em>Philippine Copyright 2024 &copy; Private Education Assistance Committee (PEAC). All rights to the information contained herein reserved by PEAC.</em>
                        </div>

                        <!-- Submit -->
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-success" name="submit_evaluation">
                                <i class="fas fa-check me-2"></i>Submit Evaluation
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <?php endif; ?>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    (function() {
        // === PEAC Indicator texts (for AI payload) ===
        const teacherActionTexts = [
            "The teacher communicates clear expectations of student performance in line with the unit standards and competencies.",
            "The teacher utilizes various learning materials, resources and strategies to enable all students to learn and achieve the unit standards and competencies and learning goals.",
            "The teacher monitors and checks on students' learning and attainment of the unit standards and competencies by conducting varied forms of assessments during class discussion.",
            "The teacher provides appropriate feedback or interventions to enable students in attaining the unit standards and competencies.",
            "The teacher manages the classroom environment and time in a way that supports student learning and the achievement of the unit standards and competencies.",
            "The teacher processes students' understanding by asking clarifying or critical thinking questions related to the unit standards and competencies."
        ];
        const studentActionTexts = [
            "The students are active and engaged with the different learning tasks aimed at accomplishing the unit standards and competencies.",
            "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
            "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
            "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
            "The students are able to explain how their ideas, outputs or performances accomplish the unit standards and competencies.",
            "The students, when encouraged or on their own, ask questions to clarify or deepen their understanding of the unit standards and competencies.",
            "The students are able to relate or transfer their learning to daily life and real world situations.",
            "The students are able to integrate 21st century skills in their achievement of the unit standards and competencies.",
            "The students are able to reflect on and connect their learning with the school's PVMGO."
        ];

        // === Average Calculation ===
        function calculateAverages() {
            let teacherTotal = 0, teacherCount = 0;
            for (let i = 0; i < 6; i++) {
                const checked = document.querySelector('input[name="teacher_action' + i + '"]:checked');
                if (checked) { teacherTotal += parseInt(checked.value); teacherCount++; }
            }
            let studentTotal = 0, studentCount = 0;
            for (let i = 0; i < 9; i++) {
                const checked = document.querySelector('input[name="student_action' + i + '"]:checked');
                if (checked) { studentTotal += parseInt(checked.value); studentCount++; }
            }
            const totalItems = teacherCount + studentCount;
            const totalSum = teacherTotal + studentTotal;
            const avg = totalItems > 0 ? (totalSum / totalItems).toFixed(2) : '0.00';
            document.getElementById('totalAverage').textContent = avg;

            const taAvg = teacherCount > 0 ? teacherTotal / teacherCount : 0;
            const saAvg = studentCount > 0 ? studentTotal / studentCount : 0;
            return {
                teacher_actions: parseFloat(taAvg.toFixed(2)),
                student_learning_actions: parseFloat(saAvg.toFixed(2)),
                overall: parseFloat(avg)
            };
        }

        document.addEventListener('change', function(e) {
            if (e.target && e.target.type === 'radio' && (e.target.name.startsWith('teacher_action') || e.target.name.startsWith('student_action'))) {
                calculateAverages();
            }
        });

        // === Signature Pad ===
        function initSignaturePad(canvasId, role) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return null;
            const ctx = canvas.getContext('2d');
            let drawing = false;

            function resizeCanvas() {
                const ratio = window.devicePixelRatio || 1;
                const rect = canvas.getBoundingClientRect();
                const w = Math.max(1, Math.floor(rect.width * ratio));
                const h = Math.max(1, Math.floor(rect.height * ratio));
                if (canvas.width !== w || canvas.height !== h) {
                    canvas.width = w;
                    canvas.height = h;
                    ctx.lineWidth = 2 * ratio;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#000';
                }
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            function getPos(e) {
                const r = canvas.getBoundingClientRect();
                const scaleX = canvas.width / r.width;
                const scaleY = canvas.height / r.height;
                const t = e.touches ? e.touches[0] : e;
                return { x: (t.clientX - r.left) * scaleX, y: (t.clientY - r.top) * scaleY };
            }

            function start(e) { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
            function move(e) { if (!drawing) return; e.preventDefault(); const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
            function stop() { drawing = false; }

            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            canvas.addEventListener('mouseup', stop);
            canvas.addEventListener('mouseleave', stop);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', stop);

            return { canvas, ctx, clear() { ctx.clearRect(0, 0, canvas.width, canvas.height); }, resize() { resizeCanvas(); }, toDataURL() { return canvas.toDataURL(); } };
        }

        const pads = {};
        document.querySelectorAll('[data-toggle-sign]').forEach(btn => {
            btn.addEventListener('click', function() {
                const role = this.getAttribute('data-toggle-sign');
                const wrap = document.querySelector('[data-sign-wrap="' + role + '"]');
                if (wrap) {
                    wrap.style.display = wrap.style.display === 'none' ? '' : 'none';
                    if (!pads[role]) {
                        pads[role] = initSignaturePad(role + 'SignaturePad', role);
                    }
                    if (pads[role]) requestAnimationFrame(function() { pads[role].resize(); });
                }
            });
        });

        document.querySelectorAll('[data-clear-sign]').forEach(btn => {
            btn.addEventListener('click', function() {
                const role = this.getAttribute('data-clear-sign');
                if (pads[role]) pads[role].clear();
            });
        });

        document.querySelectorAll('[data-apply-sign]').forEach(btn => {
            btn.addEventListener('click', function() {
                const role = this.getAttribute('data-apply-sign');
                if (pads[role]) {
                    const dataUrl = pads[role].toDataURL();
                    document.getElementById(role + 'Signature').value = dataUrl;
                    const preview = document.getElementById(role + 'SignaturePreview');
                    if (preview) { preview.src = dataUrl; preview.style.display = 'block'; }
                    document.querySelector('[data-sign-wrap="' + role + '"]').style.display = 'none';
                }
            });
        });

        // === AI Recommendation Functions ===
        function escapeHtml(str) {
            if (!str && str !== 0) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function setAIDebugStatus(msg, show) {
            const panel = document.getElementById('aiDebugPanel');
            const text = document.getElementById('aiDebugText');
            if (panel && text) {
                text.textContent = msg;
                panel.style.display = show ? '' : 'none';
            }
        }

        function hasMeaningfulEvaluationInput() {
            let checked = 0;
            for (let i = 0; i < 6; i++) {
                if (document.querySelector('input[name="teacher_action' + i + '"]:checked')) checked++;
            }
            for (let i = 0; i < 9; i++) {
                if (document.querySelector('input[name="student_action' + i + '"]:checked')) checked++;
            }
            return checked >= 15; // all 15 indicators
        }

        function buildAIPayloadFromForm() {
            const averages = calculateAverages();
            const ratings = { teacher_actions: {}, student_learning_actions: {} };
            const indicatorComments = [];

            for (let i = 0; i < 6; i++) {
                const radio = document.querySelector('input[name="teacher_action' + i + '"]:checked');
                if (radio) {
                    ratings.teacher_actions[i] = {
                        rating: radio.value,
                        comment: '',
                        criterion_text: teacherActionTexts[i]
                    };
                }
            }
            for (let i = 0; i < 9; i++) {
                const radio = document.querySelector('input[name="student_action' + i + '"]:checked');
                if (radio) {
                    ratings.student_learning_actions[i] = {
                        rating: radio.value,
                        comment: '',
                        criterion_text: studentActionTexts[i]
                    };
                }
            }

            return {
                faculty_name: document.getElementById('facultyName')?.value || '',
                department: document.querySelector('input[name="department"]')?.value || '',
                subject_observed: document.getElementById('subjectObserved')?.value || '',
                observation_type: 'Formal',
                averages: {
                    communications: averages.teacher_actions,
                    management: averages.student_learning_actions,
                    assessment: 0,
                    overall: averages.overall
                },
                ratings,
                indicator_comments: indicatorComments,
                comments_summary: { teacher_actions: [], student_learning_actions: [] },
                evaluation_focus: '',
                evaluation_form_type: 'peac',
                style: 'standard'
            };
        }

        function renderSuggestionCards(options, targetField) {
            const list = (Array.isArray(options) ? options : []).filter(txt => String(txt || '').trim());
            if (!list.length) return '<div class="text-muted small">No suggestions available.</div>';
            const targetId = targetField === 'improvement_areas' ? 'improvementAreas' : targetField;
            return '<div class="ai-suggestion-label" style="font-size: 0.9rem; color: #6c757d; margin-bottom: 8px;">Click a suggestion to add it:</div>' +
                list.map(txt =>
                    '<div class="ai-option-card" onclick="(function(el){ var ta = document.getElementById(\'' + targetId + '\'); if(ta) ta.value = decodeURIComponent(el.getAttribute(\'data-text\')); })(this)" data-text="' + encodeURIComponent(txt) + '">' +
                    escapeHtml(txt) + '</div>'
                ).join('');
        }

        function showAISuggestions(data, dbg) {
            const panel = document.getElementById('aiSuggestionPanel');
            const strengthsBox = document.getElementById('aiSuggestionStrengths');
            const improvementsBox = document.getElementById('aiSuggestionImprovements');
            const recommendationsBox = document.getElementById('aiSuggestionRecommendations');
            const strengthsPanel = document.getElementById('aiStrengthsPanel');
            const improvementsPanel = document.getElementById('aiImprovementsPanel');
            const recommendationsPanel = document.getElementById('aiRecommendationsPanel');

            if (!panel || !strengthsBox || !improvementsBox || !recommendationsBox) return;

            strengthsBox.innerHTML = renderSuggestionCards(data.strengths_options || [data.strengths || ''], 'strengths');
            improvementsBox.innerHTML = renderSuggestionCards(data.improvement_areas_options || [data.improvement_areas || ''], 'improvement_areas');
            recommendationsBox.innerHTML = renderSuggestionCards(data.recommendations_options || [data.recommendations || ''], 'recommendations');

            panel.style.display = 'block';
            if (strengthsPanel) strengthsPanel.style.display = 'block';
            if (improvementsPanel) improvementsPanel.style.display = 'block';
            if (recommendationsPanel) recommendationsPanel.style.display = 'block';

            window.__aiSuggestionHistory = window.__aiSuggestionHistory || { strengths: [], areas_for_improvement: [], recommendations: [] };
            const pushUnique = (key, values) => {
                const bucket = window.__aiSuggestionHistory[key] || [];
                (values || []).forEach(v => { const n = String(v || '').trim(); if (n && !bucket.includes(n)) bucket.push(n); });
                window.__aiSuggestionHistory[key] = bucket.slice(-12);
            };
            pushUnique('strengths', [data.strengths, ...(data.strengths_options || [])]);
            pushUnique('areas_for_improvement', [data.improvement_areas, ...(data.improvement_areas_options || [])]);
            pushUnique('recommendations', [data.recommendations, ...(data.recommendations_options || [])]);
        }

        async function generateAINarratives(options = {}) {
            const { force = false, showAlerts = false } = options;
            const btn = document.getElementById('generateAI');

            if (!hasMeaningfulEvaluationInput()) {
                let checked = 0;
                for (let i = 0; i < 6; i++) { if (document.querySelector('input[name="teacher_action' + i + '"]:checked')) checked++; }
                for (let i = 0; i < 9; i++) { if (document.querySelector('input[name="student_action' + i + '"]:checked')) checked++; }
                const msg = 'Please complete all 15 rating indicators before generating AI recommendations (' + checked + '/15 completed).';
                setAIDebugStatus(msg, true);
                if (showAlerts) alert(msg);
                return;
            }

            const defaultBtnHtml = '<i class="fas fa-magic me-2"></i> Generate AI Recommendation';
            const restoreButton = () => {
                if (!btn) return;
                btn.disabled = false;
                const prev = btn.dataset.prevText;
                btn.innerHTML = (prev && prev.trim().length) ? prev : defaultBtnHtml;
                delete btn.dataset.prevText;
            };

            const payload = buildAIPayloadFromForm();
            payload.regeneration_nonce = force ? String(Date.now()) : '';
            payload.previously_shown = force ? {
                strengths: (window.__aiSuggestionHistory && window.__aiSuggestionHistory.strengths) || [],
                areas_for_improvement: (window.__aiSuggestionHistory && window.__aiSuggestionHistory.areas_for_improvement) || [],
                recommendations: (window.__aiSuggestionHistory && window.__aiSuggestionHistory.recommendations) || []
            } : {};

            if (btn) {
                btn.disabled = true;
                if (!btn.dataset.prevText) btn.dataset.prevText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
            }
            setAIDebugStatus('Generating\u2026 first run may take a while (model load).', true);

            try {
                const res = await fetch('../controllers/ai_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const rawText = await res.text();
                let data = null;
                try { data = JSON.parse(rawText); } catch(e) {}

                if (!res.ok || !data || data.success !== true) {
                    let msg = 'AI generation failed (HTTP ' + res.status + ').';
                    if (data && (data.message || data.error)) msg = data.message || data.error;
                    setAIDebugStatus(msg, true);
                    if (showAlerts) alert(msg);
                    restoreButton();
                    return;
                }

                const out = data.data || {};
                window.__lastAISuggestions = {
                    strengths: out.strengths || '',
                    improvement_areas: out.improvement_areas || '',
                    recommendations: out.recommendations || '',
                    strengths_options: out.strengths_options || [],
                    improvement_areas_options: out.improvement_areas_options || [],
                    recommendations_options: out.recommendations_options || []
                };
                const dbg = out.debug || {};
                const dbgParts = ['Done'];
                if (typeof dbg.reference_examples_used !== 'undefined') dbgParts.push('refs=' + dbg.reference_examples_used);
                if (typeof dbg.fallback_used !== 'undefined') dbgParts.push('fallback=' + (dbg.fallback_used ? 'yes' : 'no'));

                showAISuggestions(window.__lastAISuggestions, dbg);
                setAIDebugStatus(dbgParts.join(' | '), true);

                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-magic me-2"></i>Generate AI Recommendation Again';
                }
            } catch (err) {
                console.error(err);
                const msg = 'AI generation error. Is the AI server running on 127.0.0.1:8001?';
                setAIDebugStatus(msg, true);
                if (showAlerts) alert(msg);
            } finally {
                if (btn && btn.innerHTML.includes('Generating...')) restoreButton();
            }
        }

        // AI Generate button handler
        const genBtn = document.getElementById('generateAI');
        if (genBtn) {
            genBtn.addEventListener('click', function() {
                generateAINarratives({ force: true, showAlerts: true });
            });
        }

        // === Form Submit ===
        const form = document.getElementById('peacEvaluationForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Validate all radios
                const allGroups = new Set();
                form.querySelectorAll('input[type="radio"][required]').forEach(r => allGroups.add(r.name));
                for (const name of allGroups) {
                    if (!form.querySelector('input[name="' + name + '"]:checked')) {
                        alert('Please rate all indicators before submitting.');
                        return;
                    }
                }

                // Validate signatures
                if (!document.getElementById('raterSignature').value) {
                    alert('Please provide the Rater/Observer signature.');
                    return;
                }
                if (!document.getElementById('facultySignature').value) {
                    alert('Please provide the Faculty signature.');
                    return;
                }

                // Build form data with native PEAC categories
                const fd = new FormData(form);

                // For JHS/ELEM: grade_level_section input overrides subject_area
                const glsVal = fd.get('grade_level_section');
                if (glsVal) fd.set('subject_area', glsVal);

                // Map teacher_action radios to teacher_actions category for the controller
                for (let i = 0; i < 6; i++) {
                    const val = fd.get('teacher_action' + i);
                    fd.set('teacher_actions' + i, val || '');
                }
                // Map student_action radios to student_learning_actions category
                for (let i = 0; i < 9; i++) {
                    const val = fd.get('student_action' + i);
                    fd.set('student_learning_actions' + i, val || '');
                }

                // Set observation_time from the subject field
                fd.set('observation_time', fd.get('subject_observed') || '');

                const submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

                fetch('../controllers/EvaluationController.php?action=submit_evaluation', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Evaluation submitted successfully!');
                        window.location.href = 'dashboard.php';
                    } else {
                        alert('Error: ' + (data.message || 'Submission failed.'));
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Submit Evaluation';
                    }
                })
                .catch(err => {
                    alert('Network error. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Submit Evaluation';
                });
            });
        }
    })();
    </script>
</body>
</html>
