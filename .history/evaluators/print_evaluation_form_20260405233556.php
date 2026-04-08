<?php
require_once '../auth/session-check.php';

if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/program_assignments.php';

$db = (new Database())->getConnection();

// Load form settings (fallback for old evaluations without snapshot)
$_formSettings = [];
try {
    $fsStmt = $db->query("SELECT setting_key, setting_value FROM form_settings");
    while ($r = $fsStmt->fetch(PDO::FETCH_ASSOC)) { $_formSettings[$r['setting_key']] = $r['setting_value']; }
} catch (PDOException $e) {}
$_fs = [
    'form_code_no'   => htmlspecialchars($_formSettings['form_code_no'] ?? 'FM-DPM-SMCC-RTH-04'),
    'issue_status'   => htmlspecialchars($_formSettings['issue_status'] ?? '02'),
    'revision_no'    => htmlspecialchars($_formSettings['revision_no'] ?? '02'),
    'date_effective' => htmlspecialchars($_formSettings['date_effective'] ?? '13 September 2023'),
    'approved_by'    => htmlspecialchars($_formSettings['approved_by'] ?? 'President'),
];

$evaluationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($evaluationId <= 0) {
    http_response_code(400);
    echo 'Missing evaluation id.';
    exit();
}

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

// Use per-evaluation snapshot of form settings if available (so past evaluations are not affected by changes)
if (!empty($eval['fs_form_code_no'])) {
    $_fs = [
        'form_code_no'   => htmlspecialchars($eval['fs_form_code_no']),
        'issue_status'   => htmlspecialchars($eval['fs_issue_status']),
        'revision_no'    => htmlspecialchars($eval['fs_revision_no']),
        'date_effective' => htmlspecialchars($eval['fs_date_effective']),
        'approved_by'    => htmlspecialchars($eval['fs_approved_by']),
    ];
}

// Allow access if user is the teacher being evaluated
$isOwnEvaluation = false;
$tchkStmt = $db->prepare("SELECT id FROM teachers WHERE user_id = :uid LIMIT 1");
$tchkStmt->bindParam(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
$tchkStmt->execute();
$myTeacher = $tchkStmt->fetch(PDO::FETCH_ASSOC);
if ($myTeacher && (int)$myTeacher['id'] === (int)$eval['teacher_id']) {
    $isOwnEvaluation = true;
}

// Coordinators can only view their own evaluations within assigned programs
if (!$isOwnEvaluation && in_array($_SESSION['role'] ?? '', ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
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

$detailMap = [];
foreach ($details as $d) {
    $cat = $d['category'] ?? '';
    $idx = (int)($d['criterion_index'] ?? 0);
    if (!isset($detailMap[$cat])) $detailMap[$cat] = [];
    $detailMap[$cat][$idx] = $d;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ratingChecked($current, $value) { return ((string)$current === (string)$value) ? 'checked' : ''; }
function avgOrZero($v) { return ($v === null || $v === '') ? 0 : (float)$v; }

$communicationsAvg = avgOrZero($eval['communications_avg'] ?? 0);
$managementAvg     = avgOrZero($eval['management_avg'] ?? 0);
$assessmentAvg     = avgOrZero($eval['assessment_avg'] ?? 0);
$overallAvg        = avgOrZero($eval['overall_avg'] ?? 0);

// Compute interpretation text
if ($overallAvg >= 4.6) $interpretationText = 'Excellent';
elseif ($overallAvg >= 3.6) $interpretationText = 'Very Satisfactory';
elseif ($overallAvg >= 2.6) $interpretationText = 'Satisfactory';
elseif ($overallAvg >= 1.6) $interpretationText = 'Below Satisfactory';
elseif ($overallAvg >= 1.0) $interpretationText = 'Needs Improvement';
else $interpretationText = 'Not Rated';

$autoPrint = !empty($_GET['auto_print']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Evaluation Form - <?php echo h($eval['teacher_name']); ?></title>
    <style>
        @page { size: A4 portrait; margin: 6mm 10mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 11px; color: #000; margin: 0; padding: 10px; background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

        /* Header */
        .print-header { text-align: center; margin-bottom: 6px; }
        .print-header table.header-layout { margin: 0 auto; border-collapse: collapse; }
        .print-header table.header-layout td { border: none; vertical-align: middle; padding: 0; }
        .print-header table.header-layout td.logo-cell { padding-right: 14px; }
        .print-header img { width: 68px; height: 68px; }
        .print-header .header-text { text-align: center; }
        .print-header h4 { margin: 0; font-size: 16px; font-weight: 700; }
        .print-header p { margin: 1px 0; font-size: 9px; }
        .print-header a { color: #000; text-decoration: underline; font-size: 9px; }
        .eval-title { text-align: center !important; font-weight: 700; font-size: 13px; margin: 6px auto 4px; letter-spacing: 0.5px; clear: both; }

        /* Section titles */
        .section-title { font-weight: 700; font-size: 11px; margin: 5px 0 2px; }

        /* PART 1 info table */
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 10px; }
        .info-table td { border: 1px solid #000; padding: 3px 6px; }
        .info-table td.label { font-weight: 600; white-space: nowrap; }

        /* PART 2 mandatory */
        .mandatory-box { border: 1px solid #000; padding: 4px 8px; margin-bottom: 6px; font-size: 10px; }
        .mandatory-box p { margin: 2px 0; }

        /* Rating scale detailed */
        .rating-scale-box { border: 1px solid #000; padding: 4px 8px; margin-bottom: 4px; font-size: 9.5px; }
        .rating-scale-box table { width: 100%; }
        .rating-scale-box td { padding: 1px 4px; vertical-align: top; }
        .rating-scale-box td:first-child { font-weight: 700; white-space: nowrap; width: 165px; }

        /* Evaluation tables */
        .eval-table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
        .eval-table th, .eval-table td { border: 1px solid #000; padding: 2px 4px; font-size: 9.5px; }
        .eval-table th { background: #f5f5f5; font-weight: 700; text-align: center; }
        .eval-table td.indicator { text-align: left; }
        .eval-table td.num { text-align: center; width: 18px; font-weight: 600; }
        .eval-table td.rating-cell { text-align: center; width: 22px; }
        .eval-table td.comments-cell { width: 16%; font-size: 9px; }
        .eval-table .cat-header td { font-weight: 700; background: #f5f5f5; }
        .avg-row { text-align: center; font-weight: 700; font-size: 10px; margin: 1px 0 4px; }
        .avg-row .avg-line { display: inline-block; width: 100px; border-bottom: 1px solid #000; text-align: center; margin-left: 4px; }

        /* Total average + interpretation */
        .total-avg-row { font-weight: 700; font-size: 10px; margin: 2px 0 1px; border-top: 1px solid #000; padding-top: 2px; }
        .total-avg-row .avg-line { display: inline-block; width: 100px; border-bottom: 1px solid #000; text-align: center; margin-left: 4px; }
        .interpretation-box { font-size: 9.5px; margin-bottom: 4px; }
        .interpretation-box table td { padding: 0 6px; }
        .interpretation-box td:first-child { font-weight: 600; white-space: nowrap; }

        /* Narrative table */
        .narrative-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; table-layout: fixed; }
        .narrative-table td { border: 1.5px solid #000; padding: 5px 8px; font-size: 10px; vertical-align: top; width: 50%; }
        .narrative-table td[colspan="2"] { width: 100%; }
        .narrative-table .n-label { font-weight: 700; font-size: 11px; margin-bottom: 4px; }
        .narrative-table .n-content { min-height: 30px; font-size: 10px; line-height: 1.45; text-align: justify; overflow-wrap: break-word; word-break: normal; white-space: normal; }

        /* Signature section */
        .sig-section { margin-top: 6px; page-break-inside: avoid; font-size: 10px; }
        .sig-section h6 { font-size: 11px; font-weight: 700; margin: 0 0 2px; }
        .sig-section p.cert { margin: 0 0 6px; font-size: 9px; font-style: italic; }
        .sig-row { display: flex; gap: 30px; margin-bottom: 4px; }
        .sig-col { flex: 1; }
        .sig-img { height: 50px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 46px; max-width: 100%; object-fit: contain; }
        .sig-line { border-top: 1px solid #000; text-align: center; padding-top: 2px; font-weight: 600; font-size: 10px; }
        .sig-caption { text-align: center; font-size: 8.5px; color: #444; }
        .sig-date-row { display: flex; gap: 30px; margin-top: 2px; }
        .sig-date-col { flex: 1; font-size: 10px; }

        /* Footer */
        .page-footer { margin-top: 6px; }
        .page-footer img { width: 100%; height: auto; }

        /* Print controls */
        .no-print { }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0 0 35px 0; margin: 0; }
            .eval-table { page-break-inside: avoid; }
            .narrative-table { page-break-inside: avoid; }
            .sig-section { page-break-inside: avoid; }
            .page-footer { position: fixed; bottom: 0; left: 0; right: 0; margin: 0; padding: 0; z-index: 9999; }
            .page-footer img { width: 100%; height: auto; }
        }
        .print-btn-bar { text-align: center; margin-bottom: 12px; }
        input[type="radio"] { pointer-events: none; margin: 0; width: 12px; height: 12px; }
        input[type="checkbox"] { pointer-events: none; }
    </style>
</head>
<body>

<div class="no-print print-btn-bar">
    <button onclick="window.print()" style="padding: 8px 24px; font-size: 14px; cursor: pointer; border: 1px solid #333; border-radius: 4px; background: #fff;">
        🖨️ Print this Evaluation Form
    </button>
    <button onclick="window.close()" style="padding: 8px 24px; font-size: 14px; cursor: pointer; margin-left: 8px; border: 1px solid #333; border-radius: 4px; background: #fff;">
        ✕ Close
    </button>
</div>

<!-- Print Header with Logo -->
<div style="position:relative; margin-bottom:6px; min-height:68px;">
    <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC Logo" style="width:68px; height:68px; position:absolute; left:60px; top:0;">
    <div style="text-align:center; padding-top:2px;">
        <div style="font-size:16px; font-weight:700; margin:0;">Saint Michael College of Caraga</div>
        <div style="font-size:9px; margin:1px 0;">Brgy. 4, Nasipit, Agusan del Norte, Philippines</div>
        <div style="font-size:9px; margin:1px 0;">District 8, Brgy. Triangulo, Nasipit, Agusan del Norte, Philippines</div>
        <div style="font-size:9px; margin:1px 0;">Tel. Nos. +63 085 343-3251 / +63 085 283-3113</div>
        <a href="http://www.smccnasipit.edu.ph" style="font-size:9px; color:#000; text-decoration:underline;">www.smccnasipit.edu.ph</a>
    </div>
</div>

<div style="text-align:center; font-weight:700; font-size:13px; margin:6px 0 4px; letter-spacing:0.5px;">CLASSROOM EVALUATION FORM</div>

<!-- PART 1: Faculty Information -->
<div class="section-title">PART 1: Faculty Information</div>
<table class="info-table">
    <colgroup>
        <col style="width:12%;">
        <col style="width:22%;">
        <col style="width:16%;">
        <col style="width:22%;">
        <col style="width:28%;">
    </colgroup>
    <tr>
        <td class="label">Name of Faculty:</td>
        <td><?php echo h($eval['teacher_name']); ?></td>
        <td class="label">Academic Year:</td>
        <td><?php echo h($eval['academic_year'] ?? ''); ?></td>
        <td style="font-size:9px; white-space:nowrap;">Semester: ( <?php echo ($eval['semester'] ?? '') === '1st' ? '✓' : '&nbsp;'; ?> ) 1st ( <?php echo ($eval['semester'] ?? '') === '2nd' ? '✓' : '&nbsp;'; ?> ) 2nd</td>
    </tr>
    <tr>
        <td class="label">Department:</td>
        <td><?php echo h($eval['teacher_department'] ?? ''); ?></td>
        <td class="label">Subject/Time of Observation:</td>
        <td colspan="2"><?php echo h($eval['subject_observed'] ?? ''); ?></td>
    </tr>
    <tr>
        <td colspan="2"></td>
        <td class="label">Date of Observation:</td>
        <td colspan="2"><?php echo h($eval['observation_date'] ?? ''); ?></td>
    </tr>
    <tr>
        <td colspan="5">Type of Classroom Observation: &nbsp;Please check the appropriate box. ( <?php echo ($eval['observation_type'] ?? '') === 'Formal' ? '✓' : '&nbsp;'; ?> ) Formal &nbsp; , &nbsp; ( <?php echo ($eval['observation_type'] ?? '') === 'Informal' ? '✓' : '&nbsp;'; ?> ) Informal</td>
    </tr>
</table>

<!-- PART 2: Mandatory Requirements -->
<div class="section-title">PART 2: Mandatory Requirements for Teachers</div>
<div class="mandatory-box">
    <p>Write (/) if presented to the observer, (x) if not presented.</p>
    <p>
        ( <?php echo !empty($eval['seat_plan']) ? '/' : '&nbsp;'; ?> ) seat plan &nbsp;&nbsp;
        ( <?php echo !empty($eval['course_syllabi']) ? '/' : '&nbsp;'; ?> ) course syllabi &nbsp;&nbsp;
        ( <?php echo !empty($eval['others_requirements']) ? '/' : '&nbsp;'; ?> ) others, please specify: <?php echo h($eval['others_specify'] ?? ''); ?>
    </p>
</div>

<!-- PART 3: Domains -->
<div class="section-title">PART 3: Domains of Teaching Performance</div>

<!-- Rating Scale with full descriptions -->
<div class="rating-scale-box">
    <table>
        <tr><td>5 – Excellent</td><td>- the teacher manifested the performance indicator which greatly exceeds standards</td></tr>
        <tr><td>4 – Very Satisfactory</td><td>- the teacher manifested the performance indicator which more than meets standards</td></tr>
        <tr><td>3 – Satisfactory</td><td>- the teacher manifested the performance indicator which meets standards</td></tr>
        <tr><td>2 – Below Satisfactory</td><td>- the teacher manifested the performance indicator which falls below standards</td></tr>
        <tr><td>1 – Needs Improvement</td><td>- the teacher barely manifested the expected performance indicator</td></tr>
    </table>
</div>

<?php
$commIndicators = [
    "Uses an audible voice that can be heard at the back of the room.",
    "Speaks fluently in the language of instruction.",
    "Facilitates a dynamic discussion.",
    "Uses engaging non-verbal cues (facial expression, gestures).",
    "Uses words & expressions suited to the level of the students.",
];
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
$assIndicators = [
    "Monitors students' understanding on key concepts discussed.",
    "Uses assessment tool that relates specific course competencies stated in the syllabus.",
    "Design test/quarter/assignments and other assessment tasks that are corrector-based.",
    "Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.",
    "Conducts normative assessment before evaluating and grading the learner's performance outcome.",
    "Monitors the formative assessment results and find ways to ensure learning for the learners.",
];

$domains = [
    ['title' => 'Communications Competence', 'key' => 'communications', 'indicators' => $commIndicators, 'avg' => $communicationsAvg],
    ['title' => 'Management and Presentation of the Lesson', 'key' => 'management', 'indicators' => $mgmtIndicators, 'avg' => $managementAvg],
    ['title' => 'Assessment of Students\' Learning', 'key' => 'assessment', 'indicators' => $assIndicators, 'avg' => $assessmentAvg],
];

foreach ($domains as $domain):
?>
<table class="eval-table">
    <tr class="cat-header">
        <td colspan="2" style="text-align:left;"><?php echo h($domain['title']); ?></td>
        <td style="text-align:center; font-weight:700;">5</td>
        <td style="text-align:center; font-weight:700;">4</td>
        <td style="text-align:center; font-weight:700;">3</td>
        <td style="text-align:center; font-weight:700;">2</td>
        <td style="text-align:center; font-weight:700;">1</td>
        <td style="text-align:center; font-weight:700;">Comments</td>
    </tr>
    <?php foreach ($domain['indicators'] as $i => $indicator):
        $row = $detailMap[$domain['key']][$i] ?? null;
        $rating = $row['rating'] ?? '';
        $comment = $row['comments'] ?? '';
    ?>
    <tr>
        <td class="num"><?php echo ($i + 1); ?>.</td>
        <td class="indicator"><?php echo h($indicator); ?></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 5); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
        <td class="comments-cell"><?php echo h($comment); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<div class="avg-row">Average:<span class="avg-line"><?php echo number_format($domain['avg'], 1); ?></span></div>
<?php endforeach; ?>

<!-- Total Average + Interpretation -->
<div class="total-avg-row">Total Average:<span class="avg-line"><?php echo number_format($overallAvg, 1); ?></span></div>
<div style="font-weight: 700; font-size: 10px; margin: 2px 0 4px; padding-left: 20px;">Interpretation: <?php echo h($interpretationText); ?></div>
<div class="interpretation-box">
    <strong>Interpretation of Over-all Rating</strong>
    <table>
        <tr><td>4.6-5.0 – Excellent</td></tr>
        <tr><td>3.6-4.5 – Very Satisfactory</td></tr>
        <tr><td>2.6-3.5 – Satisfactory</td></tr>
        <tr><td>1.6-2.5 – Below Satisfactory</td></tr>
        <tr><td>1.0-1.5 – Needs Improvement</td></tr>
    </table>
</div>

<!-- Narrative Fields in table layout -->
<table class="narrative-table">
    <tr>
        <td>
            <div class="n-label">STRENGTH/S:</div>
            <div class="n-content"><?php echo nl2br(h($eval['strengths'] ?? '')); ?></div>
        </td>
        <td>
            <div class="n-label">AREAS FOR IMPROVEMENT:</div>
            <div class="n-content"><?php echo nl2br(h($eval['improvement_areas'] ?? '')); ?></div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="n-label">RECOMMENDATION/S:</div>
            <div class="n-content"><?php echo nl2br(h($eval['recommendations'] ?? '')); ?></div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="n-label">AGREEMENT:</div>
            <div class="n-content"><?php echo nl2br(h($eval['agreement'] ?? '')); ?></div>
        </td>
    </tr>
</table>

<!-- Signatures -->
<div class="sig-section">
    <h6>Rater/Observer:</h6>
    <p class="cert">I certify that this classroom evaluation represents my best judgment.</p>
    <?php
        $raterPrinted = trim((string)($eval['rater_printed_name'] ?? ''));
        if ($raterPrinted === '') $raterPrinted = trim((string)($eval['evaluator_name'] ?? ''));
        $raterSig = trim((string)($eval['rater_signature'] ?? ''));
    ?>
    <div class="sig-row">
        <div class="sig-col">
            <div class="sig-img">
                <?php if ($raterSig !== '' && strpos($raterSig, 'data:image/') === 0): ?>
                    <img src="<?php echo h($raterSig); ?>" alt="Rater signature" />
                <?php endif; ?>
            </div>
            <div class="sig-line"><?php echo h($raterPrinted ?: ''); ?></div>
            <div class="sig-caption">Signature over printed name</div>
        </div>
        <div class="sig-col">
            <div style="height: 50px; display: flex; align-items: flex-end; justify-content: center; font-weight: 600;"><?php echo h($eval['rater_date'] ?? ''); ?></div>
            <div class="sig-line"></div>
            <div class="sig-caption">Date</div>
        </div>
    </div>

    <h6 style="margin-top: 10px;">Faculty:</h6>
    <p class="cert">I certify that this evaluation result has been discussed with me during the post conference/debriefing.</p>
    <?php
        $facultyPrinted = trim((string)($eval['faculty_printed_name'] ?? ''));
        if ($facultyPrinted === '') $facultyPrinted = trim((string)($eval['teacher_name'] ?? ''));
        $facultySig = trim((string)($eval['faculty_signature'] ?? ''));
    ?>
    <div class="sig-row">
        <div class="sig-col">
            <div class="sig-img">
                <?php if ($facultySig !== '' && strpos($facultySig, 'data:image/') === 0): ?>
                    <img src="<?php echo h($facultySig); ?>" alt="Faculty signature" />
                <?php endif; ?>
            </div>
            <div class="sig-line"><?php echo h($facultyPrinted ?: ''); ?></div>
            <div class="sig-caption">Signature of Faculty over printed name</div>
        </div>
        <div class="sig-col">
            <div style="height: 50px; display: flex; align-items: flex-end; justify-content: center; font-weight: 600;"><?php echo h($eval['faculty_date'] ?? ''); ?></div>
            <div class="sig-line"></div>
            <div class="sig-caption">Date</div>
        </div>
    </div>
</div>

<!-- Form Code Box -->
<div style="border: 1.5px solid #1a237e; border-radius: 4px; padding: 0; margin-top: 12px; margin-bottom: 12px; max-width: 300px; font-size: 8.5px; page-break-inside: avoid; overflow: hidden; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="background-color: #1a237e !important; color: #fff !important; font-weight: bold; width: 40%; padding: 2px 6px; font-size: 8.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: none;">Form Code No.</td>
            <td style="padding: 2px 6px; font-size: 8.5px; border: none;">: <?php echo $_fs['form_code_no']; ?></td>
        </tr>
        <tr>
            <td style="background-color: #1a237e !important; color: #fff !important; font-weight: bold; padding: 2px 6px; font-size: 8.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: none;">Issue Status</td>
            <td style="padding: 2px 6px; font-size: 8.5px; border: none;">: <?php echo $_fs['issue_status']; ?></td>
        </tr>
        <tr>
            <td style="background-color: #1a237e !important; color: #fff !important; font-weight: bold; padding: 2px 6px; font-size: 8.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: none;">Revision No.</td>
            <td style="padding: 2px 6px; font-size: 8.5px; border: none;">: <?php echo $_fs['revision_no']; ?></td>
        </tr>
        <tr>
            <td style="background-color: #1a237e !important; color: #fff !important; font-weight: bold; padding: 2px 6px; font-size: 8.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: none;">Date Effective</td>
            <td style="padding: 2px 6px; font-size: 8.5px; border: none;">: <?php echo $_fs['date_effective']; ?></td>
        </tr>
        <tr>
            <td style="background-color: #1a237e !important; color: #fff !important; font-weight: bold; padding: 2px 6px; font-size: 8.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; border: none;">Approved By</td>
            <td style="padding: 2px 6px; font-size: 8.5px; border: none;">: <?php echo $_fs['approved_by']; ?></td>
        </tr>
    </table>
</div>

<!-- Footer -->
<div class="page-footer">
    <img src="../assets/img/footer_member.png" alt="Member Footer" style="width:100%; height:auto;">
</div>

<?php if ($autoPrint): ?>
<script>window.onload = function() { window.print(); };</script>
<?php endif; ?>

</body>
</html>
