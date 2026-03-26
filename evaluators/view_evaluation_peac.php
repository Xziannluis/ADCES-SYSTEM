<?php
require_once '../auth/session-check.php';

// Allow evaluator-style roles and leaders to view an evaluation
if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/program_assignments.php';

$db = (new Database())->getConnection();

$evaluationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($evaluationId <= 0) {
    http_response_code(400);
    echo 'Missing evaluation id.';
    exit();
}

// Fetch evaluation header + teacher + evaluator names
$headerStmt = $db->prepare(
    "SELECT e.*, t.name AS teacher_name, t.department AS teacher_department, u.name AS evaluator_name
     FROM evaluations e
     JOIN teachers t ON t.id = e.teacher_id
     JOIN users u ON u.id = e.evaluator_id
     WHERE e.id = :id
     LIMIT 1"
);
$headerStmt->bindParam(':id', $evaluationId, PDO::PARAM_INT);
$headerStmt->execute();
$eval = $headerStmt->fetch(PDO::FETCH_ASSOC);

if (!$eval) {
    http_response_code(404);
    echo 'Evaluation not found.';
    exit();
}

// If this isn't a PEAC evaluation, redirect to the ISO view
if (($eval['evaluation_form_type'] ?? 'iso') !== 'peac') {
    header('Location: view_evaluation.php?id=' . $evaluationId);
    exit();
}

// Coordinators can only view their own evaluations within assigned programs
if (in_array($_SESSION['role'] ?? '', ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    if ((int)$eval['evaluator_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
        http_response_code(403);
        echo 'Access denied.';
        exit();
    }

    $programs = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $_SESSION['department'] ?? null);
    if (!empty($programs) && !in_array($eval['teacher_department'], $programs, true)) {
        http_response_code(403);
        echo 'Access denied.';
        exit();
    }
}

$detailsStmt = $db->prepare(
    "SELECT category, criterion_index, criterion_text, rating, comments
     FROM evaluation_details
     WHERE evaluation_id = :id
     ORDER BY category, criterion_index"
);
$detailsStmt->bindParam(':id', $evaluationId, PDO::PARAM_INT);
$detailsStmt->execute();
$details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

// Index details: PEAC stores teacher_actions and student_learning_actions as their own categories
$detailMap = [];
foreach ($details as $d) {
    $cat = $d['category'] ?? '';
    $idx = (int)($d['criterion_index'] ?? 0);
    if (!isset($detailMap[$cat])) $detailMap[$cat] = [];
    $detailMap[$cat][$idx] = $d;
}

// Support both old (communications/management) and new (teacher_actions/student_learning_actions) storage
$taCategory = !empty($detailMap['teacher_actions']) ? 'teacher_actions' : 'communications';
$slaCategory = !empty($detailMap['student_learning_actions']) ? 'student_learning_actions' : 'management';

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ratingChecked($current, $value) {
    return ((string)$current === (string)$value) ? 'checked' : '';
}

// PEAC uses communications_avg for Teacher Actions, management_avg for Student Actions
$teacherActionsAvg = (float)($eval['communications_avg'] ?? 0);
$studentActionsAvg = (float)($eval['management_avg'] ?? 0);
$overallAvg = (float)($eval['overall_avg'] ?? 0);

$department_map = [
    'CCIS' => 'College of Computing and Information Sciences',
    'COE'  => 'College of Education',
    'CBA'  => 'College of Business Administration',
    'CCJE' => 'College of Criminal Justice Education',
    'CAS'  => 'College of Arts and Sciences',
    'CHM'  => 'College of Hospitality Management',
    'CTE'  => 'College of Teacher Education',
    'BASIC ED' => 'Basic Education Department',
    'ELEM' => 'Elementary Department',
    'JHS'  => 'Junior High School Department',
    'SHS'  => 'Senior High School Department',
];
$dept_raw = $eval['teacher_department'] ?? ($eval['department'] ?? '');
$dept_display = $department_map[$dept_raw] ?? $dept_raw;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEAC Evaluation - View</title>
    <?php include '../includes/header.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .peac-view-wrapper {
            max-width: 960px;
            margin: 0 auto;
        }
        .evaluation-table th, .evaluation-table td {
            text-align: center;
            vertical-align: middle;
            font-size: 0.78rem;
            padding: 4px 3px;
        }
        .evaluation-table td:nth-child(1) {
            text-align: center;
            font-weight: 600;
        }
        .evaluation-table td:nth-child(2) {
            text-align: left;
        }
        .evaluation-table input[type="radio"] {
            width: 13px;
            height: 13px;
            pointer-events: none;
        }
        .peac-header {
            text-align: center;
            line-height: 1.3;
            margin-bottom: 14px;
            display: none;
        }
        @media print {
            .peac-header { display: block !important; }
        }
        .rating-scale-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 8px 12px;
            margin-bottom: 14px;
        }
        .rating-scale-box h6 { font-size: 0.8rem; margin-bottom: 4px !important; }
        .rating-scale-box .rs-item {
            display: flex;
            gap: 8px;
            font-size: 0.75rem;
            line-height: 1.4;
        }
        .rating-scale-box .rs-item span:first-child {
            font-weight: 700;
            min-width: 14px;
        }
        .section-header {
            background: #2c3e50;
            color: white;
            padding: 6px 12px;
            font-weight: 600;
            font-size: 0.82rem;
            border-radius: 4px 4px 0 0;
            margin-top: 14px;
        }
        .card-body { padding: 0.8rem !important; }
        .card-header h5 { font-size: 0.95rem; }
        /* Prevent interaction on read-only view */
        textarea, input, select {
            pointer-events: none;
        }
        .btn, a.btn {
            pointer-events: auto;
        }
        @media print {
            .no-print, .sidebar, .readonly-banner { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .container-fluid { padding: 0 !important; }
            body { background: #fff !important; }
            .card { border: none !important; box-shadow: none !important; }
            .peac-view-wrapper { max-width: 100%; }
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="container-fluid">
      <div class="peac-view-wrapper">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <a href="dashboard.php" class="btn btn-secondary no-print">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <h5 class="mb-0 text-center flex-grow-1 no-print">PEAC CLASSROOM OBSERVATION FORM</h5>
                <a href="print_evaluation_form_peac.php?id=<?php echo $evaluationId; ?>&auto_print=1" target="_blank" class="btn btn-primary no-print">
                    <i class="fas fa-print me-1"></i> Print
                </a>
            </div>

            <div class="peac-header">
                <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC" style="max-width: 70px; height:auto;" class="mb-2"><br>
                <strong>Saint Michael College of Caraga</strong><br>
                <small>Nasipit, Agusan del Norte</small><br>
                <small>Brgy. 4, Agusan del Norte, Caraga Region</small><br>
                <small>Tel. Nos. (085) 343-3251, (085) 283-3113</small><br>
                <strong class="text-primary"><?php echo h($dept_display); ?></strong>
                <h5 class="mt-3 fw-bold">CLASSROOM OBSERVATION FORM</h5>
            </div>
        </div>

        <div class="readonly-banner mt-3 mb-3">
            <strong>Read-only view.</strong>
            This is the submitted evaluation and cannot be edited.
        </div>

        <div class="card">
            <div class="card-body">
                <!-- Faculty Information -->
                <div class="mb-2" style="font-size:0.8rem; line-height:1.6;">
                    <div>Name of School: <strong>Saint Michael College of Caraga</strong></div>
                    <div>Region: <strong>Caraga Region</strong></div>
                    <div>Address: <strong>Atupan St. Brgy. 4, Poblacion, Nasipit, Agusan del Norte</strong></div>
                    <div class="d-flex flex-wrap gap-4">
                        <div>Name of Teacher: <strong><u><?php echo h($eval['teacher_name'] ?? ''); ?></u></strong></div>
                        <div>Grade Level/Section: <strong><u><?php echo h($eval['grade_level_section'] ?? '—'); ?></u></strong></div>
                    </div>
                    <div class="d-flex flex-wrap gap-4">
                        <div>Subject of Instruction: <strong><u><?php echo h($eval['subject_observed'] ?? ''); ?></u></strong></div>
                        <div>Date of Observation: <strong><u><?php
                            $obsDate = $eval['observation_date'] ?? '';
                            echo $obsDate ? date('F d, Y', strtotime($obsDate)) : '—';
                        ?></u></strong></div>
                    </div>
                    <div>Name of Observer: <strong><u><?php echo h($eval['evaluator_name'] ?? ''); ?></u></strong></div>
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
                                <th style="width:62%">Indicator</th>
                                <th style="width:7%">4</th>
                                <th style="width:7%">3</th>
                                <th style="width:7%">2</th>
                                <th style="width:7%">1</th>
                                <th style="width:7%">0</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $teacherActionIndicators = [
                                "The teacher communicates clear expectations of student performance in line with the unit standards and competencies.",
                                "The teacher utilizes various learning materials, resources and strategies to enable all students to learn and achieve the unit standards and competencies and learning goals.",
                                "The teacher monitors and checks on students' learning and attainment of the unit standards and competencies by conducting varied forms of assessments during class discussion.",
                                "The teacher provides appropriate feedback or interventions to enable students in attaining the unit standards and competencies.",
                                "The teacher manages the classroom environment and time in a way that supports student learning and the achievement of the unit standards and competencies.",
                                "The teacher processes students' understanding by asking clarifying or critical thinking questions related to the unit standards and competencies.",
                            ];
                            for ($i = 0; $i < 6; $i++):
                                $row = $detailMap[$taCategory][$i] ?? null;
                                $rating = $row['rating'] ?? '';
                            ?>
                                <tr>
                                    <td><?php echo ($i + 1) . '.'; ?></td>
                                    <td><?php echo h($teacherActionIndicators[$i]); ?></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 0); ?> disabled></td>
                                </tr>
                            <?php endfor; ?>
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
                                <th style="width:62%">Indicator</th>
                                <th style="width:7%">4</th>
                                <th style="width:7%">3</th>
                                <th style="width:7%">2</th>
                                <th style="width:7%">1</th>
                                <th style="width:7%">0</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $studentActionIndicators = [
                                "The students are active and engaged with the different learning tasks aimed at accomplishing the unit standards and competencies.",
                                "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
                                "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
                                "The students with the help of different learning materials and resources including technology achieve the learning goals of the unit standards and competencies.",
                                "The students are able to explain how their ideas, outputs or performances accomplish the unit standards and competencies.",
                                "The students, when encouraged or on their own, ask questions to clarify or deepen their understanding of the unit standards and competencies.",
                                "The students are able to relate or transfer their learning to daily life and real world situations.",
                                "The students are able to integrate 21st century skills in their achievement of the unit standards and competencies.",
                                "The students are able to reflect on and connect their learning with the school's PVMGO.",
                            ];
                            for ($i = 0; $i < 9; $i++):
                                $row = $detailMap[$slaCategory][$i] ?? null;
                                $rating = $row['rating'] ?? '';
                            ?>
                                <tr>
                                    <td><?php echo ($i + 7) . '.'; ?></td>
                                    <td><?php echo h($studentActionIndicators[$i]); ?></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
                                    <td><input type="radio" <?php echo ratingChecked($rating, 0); ?> disabled></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Total Average -->
                <div class="text-end mt-2 mb-2">
                    <strong>Total Average: <span class="text-primary"><?php echo number_format($overallAvg, 2); ?></span></strong>
                </div>

                <!-- Strengths and Areas for Improvement -->
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="border rounded">
                            <div class="fw-bold px-2 py-1 border-bottom" style="background:#f8f9fa; font-size:0.8rem;">STRENGTH/S:</div>
                            <div class="p-2">
                                <textarea class="form-control border-0" rows="3" style="resize:none; font-size:0.78rem;" disabled><?php echo h($eval['strengths'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded">
                            <div class="fw-bold px-2 py-1 border-bottom" style="background:#f8f9fa; font-size:0.8rem;">AREAS FOR IMPROVEMENT:</div>
                            <div class="p-2">
                                <textarea class="form-control border-0" rows="3" style="resize:none; font-size:0.78rem;" disabled><?php echo h($eval['improvement_areas'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="border rounded">
                            <div class="fw-bold px-2 py-1 border-bottom" style="background:#f8f9fa; font-size:0.8rem;">RECOMMENDATION/S:</div>
                            <div class="p-2">
                                <textarea class="form-control border-0" rows="3" style="resize:none; font-size:0.78rem;" disabled><?php echo h($eval['recommendations'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded">
                            <div class="fw-bold px-2 py-1 border-bottom" style="background:#f8f9fa; font-size:0.8rem;">AGREEMENT:</div>
                            <div class="p-2">
                                <textarea class="form-control border-0" rows="3" style="resize:none; font-size:0.78rem;" disabled><?php echo h($eval['agreement'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Signatures -->
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="border p-2">
                            <h6>Rater/Observer:</h6>
                            <p class="small">I certify that this classroom evaluation represents my best judgment.</p>
                            <div class="mb-3">
                                <label class="form-label">Printed name</label>
                                <?php
                                    $raterPrinted = trim((string)($eval['rater_printed_name'] ?? ''));
                                    if ($raterPrinted === '') {
                                        $raterPrinted = trim((string)($eval['evaluator_name'] ?? ''));
                                    }
                                ?>
                                <input type="text" class="form-control" value="<?php echo h($raterPrinted !== '' ? $raterPrinted : '—'); ?>" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Signature</label>
                                <?php $raterSig = trim((string)($eval['rater_signature'] ?? '')); ?>
                                <?php if ($raterSig !== '' && strpos($raterSig, 'data:image/') === 0): ?>
                                    <div class="border rounded p-2 bg-white">
                                        <img src="<?php echo h($raterSig); ?>" alt="Rater signature" style="max-width: 100%; height: 80px; object-fit: contain;" />
                                    </div>
                                <?php else: ?>
                                    <input type="text" class="form-control" value="<?php echo h($raterSig); ?>" disabled>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input type="text" class="form-control" value="<?php echo h($eval['rater_date'] ?? ''); ?>" disabled>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border p-2">
                            <h6>Faculty:</h6>
                            <p class="small">I certify that this evaluation result has been discussed with me during the post-conference/debriefing.</p>
                            <div class="mb-3">
                                <label class="form-label">Printed name</label>
                                <?php
                                    $facultyPrinted = trim((string)($eval['faculty_printed_name'] ?? ''));
                                    if ($facultyPrinted === '') {
                                        $facultyPrinted = trim((string)($eval['teacher_name'] ?? ''));
                                    }
                                ?>
                                <input type="text" class="form-control" value="<?php echo h($facultyPrinted !== '' ? $facultyPrinted : '—'); ?>" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Signature of Faculty</label>
                                <?php $facultySig = trim((string)($eval['faculty_signature'] ?? '')); ?>
                                <?php if ($facultySig !== '' && strpos($facultySig, 'data:image/') === 0): ?>
                                    <div class="border rounded p-2 bg-white">
                                        <img src="<?php echo h($facultySig); ?>" alt="Faculty signature" style="max-width: 100%; height: 80px; object-fit: contain;" />
                                    </div>
                                <?php else: ?>
                                    <input type="text" class="form-control" value="<?php echo h($facultySig); ?>" disabled>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input type="text" class="form-control" value="<?php echo h($eval['faculty_date'] ?? ''); ?>" disabled>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PEAC Footer -->
                <div class="mt-3 text-muted small">
                    <em>Philippine Copyright 2024 &copy; Private Education Assistance Committee (PEAC). All rights to the information contained herein reserved by PEAC.</em>
                </div>

            </div>
        </div>
      </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>
