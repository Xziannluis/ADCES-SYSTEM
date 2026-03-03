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

// Index details for quick lookup: $detailMap[category][index] = row
$detailMap = [];
foreach ($details as $d) {
    $cat = $d['category'] ?? '';
    $idx = (int)($d['criterion_index'] ?? 0);
    if (!isset($detailMap[$cat])) $detailMap[$cat] = [];
    $detailMap[$cat][$idx] = $d;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function checked($cond) {
    return $cond ? 'checked' : '';
}

function ratingChecked($current, $value) {
    return ((string)$current === (string)$value) ? 'checked' : '';
}

function avgOrZero($v) {
    if ($v === null || $v === '') return 0;
    return (float)$v;
}

$communicationsAvg = avgOrZero($eval['communications_avg'] ?? 0);
$managementAvg = avgOrZero($eval['management_avg'] ?? 0);
$assessmentAvg = avgOrZero($eval['assessment_avg'] ?? 0);
$overallAvg = avgOrZero($eval['overall_avg'] ?? 0);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classroom Evaluation - View</title>
    <?php include '../includes/header.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .readonly-banner {
            background: #eef6ff;
            border: 1px solid #cde3ff;
            padding: 10px 12px;
            border-radius: 8px;
        }

        /* Show original form layout but prevent changes */
        .evaluation-table input[type="radio"],
        .evaluation-table input[type="text"],
        textarea, input, select {
            pointer-events: none;
        }

        /* Keep Back button clickable */
        .btn, a.btn {
            pointer-events: auto;
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <h5 class="mb-0 text-center flex-grow-1">CLASSROOM EVALUATION FORM</h5>
                <div style="width: 90px;"></div>
            </div>
        </div>

        <div class="readonly-banner mt-3 mb-3">
            <strong>Read-only view.</strong>
            This is the submitted evaluation and cannot be edited.
        </div>

        <div class="card">
            <div class="card-body">
                <!-- PART 1: Faculty Information -->
                <div class="evaluation-section">
                    <h5>PART 1: Faculty Information</h5>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Name of Faculty:</label>
                            <input type="text" class="form-control" value="<?php echo h($eval['teacher_name'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Academic Year:</label>
                            <input type="text" class="form-control" value="<?php echo h($eval['academic_year'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Semester:</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" <?php echo checked(($eval['semester'] ?? '') === '1st'); ?> disabled>
                                    <label class="form-check-label">1st</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" <?php echo checked(($eval['semester'] ?? '') === '2nd'); ?> disabled>
                                    <label class="form-check-label">2nd</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Department:</label>
                            <input type="text" class="form-control" value="<?php echo h($eval['teacher_department'] ?? ($eval['department'] ?? '')); ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Subject/Time of Observation:</label>
                            <input type="text" class="form-control" value="<?php echo h($eval['subject_observed'] ?? ''); ?>" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date of Observation:</label>
                            <input type="date" class="form-control" value="<?php echo h($eval['observation_date'] ?? ''); ?>" disabled>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Type of Classroom Observation:</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" <?php echo checked(($eval['observation_type'] ?? '') === 'Formal'); ?> disabled>
                                    <label class="form-check-label">Formal</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" <?php echo checked(($eval['observation_type'] ?? '') === 'Informal'); ?> disabled>
                                    <label class="form-check-label">Informal</label>
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
                                <input class="form-check-input" type="checkbox" <?php echo checked(!empty($eval['seat_plan'])); ?> disabled>
                                <label class="form-check-label">Seat Plan</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" <?php echo checked(!empty($eval['course_syllabi'])); ?> disabled>
                                <label class="form-check-label">Course Syllabi</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" <?php echo checked(!empty($eval['others_requirements'])); ?> disabled>
                                <label class="form-check-label">Others</label>
                                <input type="text" class="form-control mt-1" value="<?php echo h($eval['others_specify'] ?? ''); ?>" disabled>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rating Scale -->
                <div class="rating-scale">
                    <h6>Rating Scale:</h6>
                    <div class="rating-scale-item"><span>5 - Excellent</span><span>Greatly exceeds standards</span></div>
                    <div class="rating-scale-item"><span>4 - Very Satisfactory</span><span>More than meets standards</span></div>
                    <div class="rating-scale-item"><span>3 - Satisfactory</span><span>Meets standards</span></div>
                    <div class="rating-scale-item"><span>2 - Below Satisfactory</span><span>Falls below standards</span></div>
                    <div class="rating-scale-item"><span>1 - Needs Improvement</span><span>Barely meets expectations</span></div>
                </div>

                <!-- PART 3 -->
                <div class="evaluation-section">
                    <h5>PART 3: Domains of Teaching Performance</h5>

                    <!-- Communications -->
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
                            <tbody>
                                <?php
                                // Indicators copied from the original form so the View matches exactly
                                $commIndicators = [
                                    "Uses an audible voice that can be heard at the back of the room.",
                                    "Speaks fluently in the language of instruction.",
                                    "Facilitates a dynamic discussion.",
                                    "Uses engaging non-verbal cues (facial expression, gestures).",
                                    "Uses words & expressions suited to the level of the students.",
                                ];
                                for ($i = 0; $i < 5; $i++):
                                    $row = $detailMap['communications'][$i] ?? null;
                                    $rating = $row['rating'] ?? '';
                                    $comment = $row['comments'] ?? '';
                                ?>
                                    <tr>
                                        <td><?php echo h($commIndicators[$i]); ?></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 5); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
                                        <td><input type="text" class="form-control form-control-sm" value="<?php echo h($comment); ?>" disabled></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                        <div class="text-end"><strong>Average: <span><?php echo number_format($communicationsAvg, 1); ?></span></strong></div>
                    </div>

                    <!-- Management -->
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
                            <tbody>
                                <?php
                                $mgmtIndicators = [
                                    "The TILO (Topic Intended Learning Outcomes) are clearly presented.",
                                    "Recall and connects previous lessons to the new lessons.",
                                    "The topic/lesson is introduced in an interesting & engaging way.",
                                    "Uses current issues, real life & local examples to enrich class discussion.",
                                    "Focuses class discussion on key concepts of the lesson.",
                                    "Encourages active participation among students and ask questions about the topic.",
                                    "Uses current instructional strategies and resources.",
                                    "Designs teaching aids that facilitate understanding of key concepts.",
                                    "Adapts teaching approach in the light of student feedback and reactions.",
                                    "Aids students using thought provoking questions (Art of Questioning).",
                                    "Integrate the institutional core values to the lessons.",
                                    "Conduct the lesson using the principle of SMART",
                                ];
                                for ($i = 0; $i < 12; $i++):
                                    $row = $detailMap['management'][$i] ?? null;
                                    $rating = $row['rating'] ?? '';
                                    $comment = $row['comments'] ?? '';
                                ?>
                                    <tr>
                                        <td><?php echo h($mgmtIndicators[$i]); ?></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 5); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
                                        <td><input type="text" class="form-control form-control-sm" value="<?php echo h($comment); ?>" disabled></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                        <div class="text-end"><strong>Average: <span><?php echo number_format($managementAvg, 1); ?></span></strong></div>
                    </div>

                    <!-- Assessment -->
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
                            <tbody>
                                <?php
                                $assIndicators = [
                                    "Monitors students' understanding on key concepts discussed.",
                                    "Uses assessment tool that relates specific course competencies stated in the syllabus.",
                                    "Design test/quarter/assignments and other assessment tasks that are corrector-based.",
                                    "Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.",
                                    "Conducts normative assessment before evaluating and grading the learner's performance outcome.",
                                    "Monitors the formative assessment results and find ways to ensure learning for the learners.",
                                ];
                                for ($i = 0; $i < 6; $i++):
                                    $row = $detailMap['assessment'][$i] ?? null;
                                    $rating = $row['rating'] ?? '';
                                    $comment = $row['comments'] ?? '';
                                ?>
                                    <tr>
                                        <td><?php echo h($assIndicators[$i]); ?></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 5); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
                                        <td><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
                                        <td><input type="text" class="form-control form-control-sm" value="<?php echo h($comment); ?>" disabled></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                        <div class="text-end"><strong>Average: <span><?php echo number_format($assessmentAvg, 1); ?></span></strong></div>
                    </div>

                    <!-- Overall Rating -->
                    <div class="mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Overall Rating Interpretation</h6>
                                <div class="rating-scale">
                                    <div class="rating-scale-item"><span>4.6-5.0</span><span>Excellent</span></div>
                                    <div class="rating-scale-item"><span>3.6-4.5</span><span>Very Satisfactory</span></div>
                                    <div class="rating-scale-item"><span>2.9-3.5</span><span>Satisfactory</span></div>
                                    <div class="rating-scale-item"><span>1.8-2.5</span><span>Below Satisfactory</span></div>
                                    <div class="rating-scale-item"><span>1.0-1.5</span><span>Needs Improvement</span></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-center p-4">
                                    <h4>Total Average</h4>
                                    <div class="display-4 text-primary"><?php echo number_format($overallAvg, 1); ?></div>
                                    <h5>
                                        <?php
                                        if ($overallAvg >= 4.6) echo 'Excellent';
                                        elseif ($overallAvg >= 3.6) echo 'Very Satisfactory';
                                        elseif ($overallAvg >= 2.9) echo 'Satisfactory';
                                        elseif ($overallAvg >= 1.8) echo 'Below Satisfactory';
                                        elseif ($overallAvg > 0) echo 'Needs Improvement';
                                        else echo 'Not Rated';
                                        ?>
                                    </h5>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Narrative fields -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">STRENGTHS:</span>
                                <textarea class="form-control" id="strengths" rows="3" disabled><?php echo h($eval['strengths'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">AREAS FOR IMPROVEMENT:</span>
                                <textarea class="form-control" id="improvementAreas" rows="3" disabled><?php echo h($eval['improvement_areas'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">RECOMMENDATIONS:</span>
                                <textarea class="form-control" id="recommendations" rows="3" disabled><?php echo h($eval['recommendations'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">AGREEMENT:</span>
                                <textarea class="form-control" rows="3" disabled><?php echo h($eval['agreement'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Signatures -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="border p-3">
                                <h6>Rater/Observer</h6>
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
                                    <input type="date" class="form-control" value="<?php echo h($eval['rater_date'] ?? ''); ?>" disabled>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border p-3">
                                <h6>Faculty</h6>
                                <p class="small">I certify that this evaluation result has been discussed with me during the post conference/debriefing.</p>
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
                                    <input type="date" class="form-control" value="<?php echo h($eval['faculty_date'] ?? ''); ?>" disabled>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form code bar -->
                    <div style="border: 2px solid #000000ff; border-radius: 8px; padding: 16px; margin-top: 24px; background: #f8faff; max-width: 500px;">
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
                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>
