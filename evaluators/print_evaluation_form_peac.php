<?php
require_once '../auth/session-check.php';

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

$headerStmt = $db->prepare(
    "SELECT e.*, t.name AS teacher_name, t.department AS teacher_department, u.name AS evaluator_name, u.department AS evaluator_department
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

// PEAC uses communications_avg for Teacher Actions, management_avg for Student Actions
$teacherActionsAvg = (float)($eval['communications_avg'] ?? 0);
$studentActionsAvg = (float)($eval['management_avg'] ?? 0);
$overallAvg        = (float)($eval['overall_avg'] ?? 0);

// Department display name — use the evaluator's department (observation context)
$deptCode = $eval['evaluator_department'] ?? $eval['teacher_department'] ?? '';
$deptDisplayNames = [
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
$deptFullName = $deptDisplayNames[$deptCode] ?? ($deptCode ?: 'Department');

// Compute rating tally for display formula
$allRatings = [];
$taCategory = !empty($detailMap['teacher_actions']) ? 'teacher_actions' : 'communications';
$slaCategory = !empty($detailMap['student_learning_actions']) ? 'student_learning_actions' : 'management';
foreach ([$taCategory, $slaCategory] as $catKey) {
    if (!empty($detailMap[$catKey])) {
        foreach ($detailMap[$catKey] as $d) {
            $allRatings[] = (int)($d['rating'] ?? 0);
        }
    }
}
$ratingTally = array_count_values($allRatings);
krsort($ratingTally);
$totalIndicators = count($allRatings);

$autoPrint = !empty($_GET['auto_print']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PEAC Evaluation Form - <?php echo h($eval['teacher_name']); ?></title>
    <style>
        @page { size: A4 portrait; margin: 8mm 12mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 11px; color: #000; margin: 0; padding: 10px; background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

        /* Header */
        .form-header { position: relative; text-align: center; margin-bottom: 2px; padding-bottom: 4px; min-height: 70px; }
        .form-header .logo-left { position: absolute; left: 0; top: 0; width: 70px; }
        .form-header .logo-left img { width: 65px; height: auto; }
        .form-header .center-text { display: inline-block; text-align: center; line-height: 1.3; }
        .form-header .logo-right { position: absolute; right: 0; top: 5px; display: flex; gap: 0; align-items: center; }
        .form-header .logo-right img { max-height: 45px; width: auto; }
        .form-header .logo-right .logo-divider { width: 1px; background: #000; height: 40px; margin: 0 6px; }

        .dept-line { text-align: center; font-weight: 700; font-size: 12px; text-decoration: underline; margin: 2px 0 0; color: #00479B; padding-bottom: 4px; border-bottom: 1.5px solid #000; }

        /* Info section */
        .info-section { font-size: 10.5px; margin-bottom: 6px; line-height: 1.6; }
        .info-section .info-row { display: flex; gap: 8px; }
        .info-section .info-label { font-weight: 400; white-space: nowrap; }
        .info-section .info-value { font-weight: 700; border-bottom: 1px solid #000; min-width: 140px; text-decoration: none; }

        /* Rating scale */
        .rating-scale { font-size: 10px; margin-bottom: 6px; line-height: 1.5; }
        .rating-scale .rs-title { font-weight: 700; text-decoration: underline; margin-bottom: 1px; }
        .rating-scale div { margin: 0; }

        /* Evaluation tables */
        .eval-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .eval-table th, .eval-table td { border: 1px solid #000; padding: 2px 4px; font-size: 9.5px; }
        .eval-table th { font-weight: 700; text-align: center; }
        .eval-table td.indicator { text-align: left; }
        .eval-table td.num { text-align: center; width: 22px; font-weight: 400; }
        .eval-table td.rating-cell { text-align: center; width: 24px; }
        .eval-table .cat-header td { font-weight: 700; }

        /* Average row inline */
        .avg-row-inline { border: 1px solid #000; border-top: none; padding: 2px 8px; font-size: 10px; text-align: right; }
        .avg-row-inline .avg-line { display: inline-block; width: 80px; border-bottom: 1px solid #000; text-align: center; margin-left: 6px; font-weight: 700; }

        /* Computation formula */
        .computation { font-size: 10px; margin: 4px 0 6px; }

        /* Narrative table */
        .narrative-table { width: 100%; border-collapse: collapse; margin-bottom: 0; table-layout: fixed; }
        .narrative-table td { border: 1.5px solid #000; padding: 6px 10px; font-size: 10.5px; vertical-align: top; }
        .narrative-table td.half { width: 50%; }
        .narrative-table .n-label { font-weight: 700; font-size: 11px; margin-bottom: 4px; }
        .narrative-table .n-content { min-height: 35px; font-size: 10.5px; line-height: 1.5; text-align: left; overflow-wrap: break-word; }
        .narrative-table .n-content ul { margin: 4px 0; padding-left: 18px; }
        .narrative-table .n-content li { margin-bottom: 2px; }

        /* Signature section */
        .sig-section { margin-top: 14px; page-break-inside: avoid; font-size: 11px; }
        .sig-section .sig-title { font-weight: 700; font-size: 11px; margin: 8px 0 2px; }
        .sig-section .cert-text { margin: 0 0 8px; font-size: 10px; }
        .sig-row { display: flex; gap: 40px; margin-bottom: 6px; }
        .sig-col { flex: 1; }
        .sig-img { height: 50px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 46px; max-width: 100%; object-fit: contain; }
        .sig-name-line { border-top: 1px solid #000; text-align: center; padding-top: 2px; font-weight: 700; font-size: 10.5px; }
        .sig-caption { text-align: center; font-size: 8.5px; color: #333; }

        /* Footer */
        .page-footer { margin-top: 8px; }
        .page-footer img { width: 100%; height: auto; }

        /* Copyright */
        .peac-copyright { font-size: 8px; text-align: center; font-style: italic; color: #555; margin-top: 6px; }

        /* Print controls */
        .no-print { }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            .page-footer { position: fixed; bottom: 0; left: 0; right: 0; margin: 0; padding: 0; z-index: 9999; }
            .page-footer img { width: 100%; height: auto; }
        }
        .print-btn-bar { text-align: center; margin-bottom: 12px; }
        input[type="radio"] { pointer-events: none; margin: 0; width: 13px; height: 13px; accent-color: #000; }
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

<!-- Page Header: Logo + School Info + SOCOTEC/PAB -->
<div class="form-header">
    <div class="logo-left">
        <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC Logo">
    </div>
    <div class="center-text">
        <div style="font-size:14px; font-weight:700;">Saint Michael College of Caraga</div>
        <div style="font-size:9.5px;">Nasipit, Agusan del Norte</div>
        <div style="font-size:9.5px;">Brgy. 4, Nasipit, Agusan del Norte, Caraga Region</div>
        <div style="font-size:9.5px;">Tel. Nos. (085) 343-3251, (085) 283-3113</div>
    </div>
    <div class="logo-right">
        <img src="../assets/img/socotec.jpg" alt="SOCOTEC ISO 9001">
        <div class="logo-divider"></div>
        <img src="../assets/img/pab_ab.png" alt="PAB AB" onerror="this.style.display='none'; this.previousElementSibling.style.display='none';">
    </div>
</div>
<div class="dept-line"><?php echo h($deptFullName); ?></div>

<div style="text-align:center; font-weight:700; font-size:12px; margin:0 0 8px; letter-spacing:0.3px;">CLASSROOM OBSERVATION FORM</div>

<!-- School / Teacher / Observer Info -->
<div class="info-section">
    <div>Name of School: <strong>Saint Michael College of Caraga</strong></div>
    <div>Region: <strong>Caraga Region</strong></div>
    <div>Address: <strong>Atupan St. Brgy. 4, Poblacion, Nasipit, Agusan del Norte</strong></div>
    <div>Name of Teacher: <span class="info-value"><?php echo h($eval['faculty_name'] ?? $eval['teacher_name']); ?></span></div>
    <div class="info-row">
        <div>Subject of Instruction: <span class="info-value"><?php echo h($eval['subject_observed'] ?? ''); ?></span></div>
        <div style="margin-left:auto;">Grade Level/Section: <span class="info-value"><?php echo h($eval['subject_area'] ?? ''); ?></span></div>
    </div>
    <div class="info-row">
        <div>Name of Observer: <span class="info-value"><?php echo h($eval['evaluator_name'] ?? ''); ?></span></div>
        <div style="margin-left:auto;">Date of Observation: <span class="info-value"><?php echo h($eval['observation_date'] ?? ''); ?></span></div>
    </div>
</div>

<!-- Rating Scale -->
<div class="rating-scale">
    <div class="rs-title">RATING SCALE:</div>
    <div>4 - Performance of this item is innovatively done. 3 - Performance of this item is satisfactorily done.</div>
    <div>2 - Performance of this item is partially done due to some omissions.</div>
    <div>1 - Performance of this item is partially done due to serious errors and misconceptions.</div>
    <div>0 - Performance of this item is not observed at all.</div>
</div>

<?php
$teacherActionIndicators = [
    "The teacher communicates clear expectations of student performance in line with the unit standards and competencies.",
    "The teacher utilizes various learning materials, resources and strategies to enable all students to learn and achieve the unit standards and competencies and learning goals.",
    "The teacher monitors and checks on students' learning and attainment of the unit standards and competencies by conducting varied forms of assessments during class discussion.",
    "The teacher provides appropriate feedback or interventions to enable students in attaining the unit standards and competencies.",
    "The teacher manages the classroom environment and time in a way that supports student learning and the achievement of the unit standards and competencies.",
    "The teacher processes students' understanding by asking clarifying or critical thinking questions related to the unit standards and competencies.",
];

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

$sections = [
    ['title' => 'A. TEACHER ACTIONS', 'key' => $taCategory, 'indicators' => $teacherActionIndicators, 'avg' => $teacherActionsAvg, 'start' => 1],
    ['title' => 'B. STUDENT LEARNING ACTIONS', 'key' => $slaCategory, 'indicators' => $studentActionIndicators, 'avg' => $studentActionsAvg, 'start' => 7],
];

foreach ($sections as $section):
?>
<table class="eval-table">
    <tr class="cat-header">
        <td colspan="2" style="text-align:left;"><?php echo h($section['title']); ?></td>
        <td style="text-align:center; font-weight:700; width:24px;">4</td>
        <td style="text-align:center; font-weight:700; width:24px;">3</td>
        <td style="text-align:center; font-weight:700; width:24px;">2</td>
        <td style="text-align:center; font-weight:700; width:24px;">1</td>
        <td style="text-align:center; font-weight:700; width:24px;">0</td>
    </tr>
    <?php foreach ($section['indicators'] as $i => $indicator):
        $row = $detailMap[$section['key']][$i] ?? null;
        $rating = $row['rating'] ?? '';
    ?>
    <tr>
        <td class="num"><?php echo ($section['start'] + $i); ?>.</td>
        <td class="indicator"><?php echo h($indicator); ?></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 4); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 3); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 2); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 1); ?> disabled></td>
        <td class="rating-cell"><input type="radio" <?php echo ratingChecked($rating, 0); ?> disabled></td>
    </tr>
    <?php endforeach; ?>
</table>
<div class="avg-row-inline">Average:<span class="avg-line"><?php echo number_format($section['avg'], 1); ?></span></div>
<?php endforeach; ?>

<!-- Computation Formula -->
<?php
$formulaParts = [];
$totalSum = 0;
foreach ($ratingTally as $rVal => $rCount) {
    $product = $rCount * $rVal;
    $totalSum += $product;
    $formulaParts[] = "({$rCount}x{$rVal}=" . ($product) . ")";
}
$formulaStr = implode(' + ', $formulaParts);
$avgComputed = $totalIndicators > 0 ? round($totalSum / $totalIndicators, 2) : 0;
?>
<div class="computation">
    <?php echo $formulaStr; ?> = <?php echo $totalSum; ?>/<?php echo $totalIndicators; ?>= <?php echo number_format($avgComputed, 2); ?>
</div>

<!-- PEAC Copyright (page 1 bottom) -->
<div class="peac-copyright">
    Philippine Copyright 2024 &copy; Private Education Assistance Committee (PEAC). All rights to the information contained herein reserved by PEAC.
</div>

<!-- Page 2: Narratives & Signatures -->
<!-- Repeat header for page 2 -->
<div style="page-break-before: always;"></div>

<div class="form-header">
    <div class="logo-left">
        <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC Logo">
    </div>
    <div class="center-text">
        <div style="font-size:14px; font-weight:700;">Saint Michael College of Caraga</div>
        <div style="font-size:9.5px;">Nasipit, Agusan del Norte</div>
        <div style="font-size:9.5px;">Brgy. 4, Nasipit, Agusan del Norte, Caraga Region</div>
        <div style="font-size:9.5px;">Tel. Nos. (085) 343-3251, (085) 283-3113</div>
    </div>
    <div class="logo-right">
        <img src="../assets/img/socotec.jpg" alt="SOCOTEC ISO 9001">
        <div class="logo-divider"></div>
        <img src="../assets/img/pab_ab.png" alt="PAB AB" onerror="this.style.display='none'; this.previousElementSibling.style.display='none';">
    </div>
</div>
<div class="dept-line"><?php echo h($deptFullName); ?></div>

<!-- Narrative Fields -->
<table class="narrative-table">
    <tr>
        <td class="half">
            <div class="n-label">STRENGTH/S</div>
            <div class="n-content"><?php echo nl2br(h($eval['strengths'] ?? '')); ?></div>
        </td>
        <td class="half">
            <div class="n-label">AREAS FOR IMPROVEMENT</div>
            <div class="n-content"><?php echo nl2br(h($eval['improvement_areas'] ?? '')); ?></div>
        </td>
    </tr>
</table>
<table class="narrative-table">
    <tr>
        <td>
            <div class="n-label">RECOMMENDATION/S</div>
            <div class="n-content"><?php echo nl2br(h($eval['recommendations'] ?? '')); ?></div>
        </td>
    </tr>
</table>
<table class="narrative-table">
    <tr>
        <td>
            <div class="n-label">Agreement:</div>
            <div class="n-content"><?php echo nl2br(h($eval['agreement'] ?? '')); ?></div>
        </td>
    </tr>
</table>

<!-- Signatures -->
<div class="sig-section">
    <div class="sig-title">Rater/Observer:</div>
    <div class="cert-text">I certify that this classroom evaluation represents my best judgment.</div>
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
            <div class="sig-name-line"><?php echo h($raterPrinted); ?></div>
            <div class="sig-caption">Signature over printed name</div>
        </div>
        <div class="sig-col">
            <div style="height: 50px; display: flex; align-items: flex-end; justify-content: center; font-weight: 600;"><?php echo h($eval['rater_date'] ?? ''); ?></div>
            <div class="sig-name-line"></div>
            <div class="sig-caption">Date</div>
        </div>
    </div>

    <div class="sig-title" style="margin-top: 16px;">Faculty:</div>
    <div class="cert-text">I certify that this evaluation result has been discussed with me during the post conference/debriefing.</div>
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
            <div class="sig-name-line"><?php echo h($facultyPrinted); ?></div>
            <div class="sig-caption">Signature of Faculty over printed name</div>
        </div>
        <div class="sig-col">
            <div style="height: 50px; display: flex; align-items: flex-end; justify-content: center; font-weight: 600;"><?php echo h($eval['faculty_date'] ?? ''); ?></div>
            <div class="sig-name-line"></div>
            <div class="sig-caption">Date</div>
        </div>
    </div>
</div>

<!-- PEAC Copyright -->
<div class="peac-copyright">
    Philippine Copyright 2024 &copy; Private Education Assistance Committee (PEAC). All rights to the information contained herein reserved by PEAC.
</div>

<!-- Footer -->
<div class="page-footer">
    <img src="../assets/img/PEAC-FOOTER.png" alt="PEAC Footer">
</div>

<?php if ($autoPrint): ?>
<script>window.onload = function() { window.print(); };</script>
<?php endif; ?>

</body>
</html>
