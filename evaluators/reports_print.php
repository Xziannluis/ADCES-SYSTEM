<?php
/**
 * Standalone print page for Classroom Observation Report (evaluators).
 * Opened in a new tab/window from reports.php — contains ONLY the
 * printable content with self-contained CSS.  No sidebar, no topbar.
 */
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Evaluation.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();

$evaluation = new Evaluation($db);
$teacher = new Teacher($db);

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
$raw_department = $is_leader ? (string)($_GET['department'] ?? '') : (string)($_SESSION['department'] ?? '');
$department_display = $department_map[$raw_department] ?? ($raw_department ?: 'All Departments');

$scoped_evaluator_id = in_array($_SESSION['role'], ['dean', 'principal', 'president', 'vice_president'], true)
    ? null
    : ($_SESSION['user_id'] ?? null);

// Available teachers (for label lookup)
$available_teachers = [];
try {
    if ($is_leader && $raw_department === '') {
        $teachersQuery = "SELECT DISTINCT t.id, t.name FROM evaluations e INNER JOIN teachers t ON e.teacher_id = t.id ORDER BY t.name ASC";
        $teachersStmt = $db->prepare($teachersQuery);
    } else {
    $teachersQuery = "SELECT DISTINCT t.id, t.name
        FROM evaluations e
        INNER JOIN teachers t ON e.teacher_id = t.id
        WHERE (t.department = :department OR e.evaluator_id = :current_user_id)";
    if ($scoped_evaluator_id !== null) {
        $teachersQuery .= " AND e.evaluator_id = :evaluator_id";
    }
    $teachersQuery .= " ORDER BY t.name ASC";
    $teachersStmt = $db->prepare($teachersQuery);
    $teachersStmt->bindValue(':department', $raw_department);
    $teachersStmt->bindValue(':current_user_id', $_SESSION['user_id']);
    if ($scoped_evaluator_id !== null) {
        $teachersStmt->bindValue(':evaluator_id', $scoped_evaluator_id);
    }
    }
    $teachersStmt->execute();
    $available_teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $available_teachers = [];
}

// Get filter parameters
$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$teacher_id = trim((string)($_GET['teacher_id'] ?? ''));

$academic_year_label = ($academic_year !== '') ? $academic_year : 'All';
$semester_label = ($semester !== '') ? $semester : 'All';
$teacher_label = 'All Teachers';
foreach ($available_teachers as $teacher_option) {
    if ((string)($teacher_option['id'] ?? '') === $teacher_id) {
        $teacher_label = (string)($teacher_option['name'] ?? 'All Teachers');
        break;
    }
}

// Get evaluations
$report_department = $is_leader ? $raw_department : '';
$evaluationsStmt = $evaluation->getEvaluationsForReport($scoped_evaluator_id, $academic_year, $semester, $teacher_id, $report_department, $is_leader ? null : $_SESSION['user_id'], $is_leader ? '' : $raw_department);
$evaluations = $evaluationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$deanPrintEvaluation = null;
foreach ($evaluations as $evaluationRow) {
    $role = strtolower(trim((string)($evaluationRow['evaluator_role'] ?? '')));
    if ($role === 'dean') {
        $deanPrintEvaluation = $evaluationRow;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Classroom Observation Report - <?php echo htmlspecialchars($_SESSION['department']); ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 5mm;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            color: #000;
            background: #f0f0f0;
            margin: 0;
            padding: 20px;
        }
        .print-page {
            width: 287mm;
            max-width: 287mm;
            margin: 0 auto;
            background: #fff;
            padding: 5mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.15);
        }
        @media print {
            body { background: #fff; padding: 0; }
            .print-page { width: 100%; max-width: 100%; box-shadow: none; padding: 0; margin: 0; }
        }
        .print-header {
            padding: 8px 0 10px;
            border-bottom: 1px solid #000;
            margin-bottom: 10px;
        }
        .print-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .print-header-left {
            width: 170px;
            text-align: left;
        }
        .print-header-center {
            flex: 1;
            text-align: center;
            line-height: 1.2;
        }
        .print-header-right {
            width: 170px;
            text-align: right;
        }
        .print-header-right-inner {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            align-items: center;
        }
        .report-info {
            padding: 4px 0;
            font-size: 10px;
            margin-bottom: 6px;
        }
        .report-info .title-block {
            text-align: center;
            margin-bottom: 4px;
        }
        .report-info .filters-block {
            text-align: right;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .report-table col.col-date { width: 7%; }
        .report-table col.col-teacher { width: 9%; }
        .report-table col.col-subject { width: 8%; }
        .report-table col.col-strength { width: 20%; }
        .report-table col.col-improvement { width: 18%; }
        .report-table col.col-recommendation { width: 17%; }
        .report-table col.col-agreement { width: 14%; }
        .report-table col.col-rating { width: 7%; }
        .report-table th {
            background: #fff;
            color: #000;
            font-weight: 700;
            font-size: 9px;
            line-height: 1.25;
            padding: 3px 4px;
            border: 1px solid #000;
            text-align: left;
        }
        .report-table td {
            font-size: 8.5px;
            font-weight: normal;
            line-height: 1.25;
            word-break: break-word;
            overflow-wrap: anywhere;
            padding: 2px 3px;
            hyphens: auto;
            vertical-align: top;
            border: 1px solid #000;
        }
        .report-table th:first-child,
        .report-table td:first-child {
            font-size: 8px;
            word-break: normal;
            overflow-wrap: normal;
        }
        .observation-notes {
            font-size: 8.5px;
            line-height: 1.25;
        }
        .observation-notes ul {
            margin: 0;
            padding-left: 14px;
        }
        .observation-notes li {
            margin-bottom: 0;
        }
        .report-table td small {
            font-size: 8px;
            line-height: 1.2;
        }
        .ratings-cell {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            width: 100%;
            text-align: center;
        }
        .ratings-cell .rating-score {
            font-weight: 700;
            font-size: 10px;
        }
        .ratings-cell .rating-label {
            font-weight: 600;
            font-size: 8px;
            line-height: 1.2;
        }
        .print-signature-block {
            display: flex;
            justify-content: flex-start;
            margin-top: 6px;
            padding-top: 2px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .print-signature-card {
            width: 200px;
            text-align: center;
        }
        .print-signature-image-wrap {
            height: 36px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            margin-bottom: 1px;
            overflow: hidden;
        }
        .print-signature-image {
            max-width: 100%;
            max-height: 32px;
            object-fit: contain;
        }
        .print-signature-line {
            border-top: 1px solid #000;
            padding-top: 2px;
            font-size: 9px;
            font-weight: 600;
        }
        .print-signature-role {
            font-size: 8px;
            font-weight: 600;
            margin-top: 1px;
        }
        .no-data {
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #666;
        }
        /* Print button — hidden when actually printing */
        .print-btn-bar {
            text-align: center;
            padding: 12px 0;
            margin-bottom: 8px;
        }
        .print-btn-bar button {
            padding: 8px 24px;
            font-size: 14px;
            cursor: pointer;
            background: #2c3e50;
            color: #fff;
            border: none;
            border-radius: 4px;
        }
        @media print {
            .print-btn-bar { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="print-btn-bar">
        <button onclick="window.print()">🖨️ Print Report</button>
    </div>

    <div class="print-page">

    <!-- Header (letterhead) -->
    <div class="print-header">
        <div class="print-header-inner">
            <div class="print-header-left">
                <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC" style="max-width: 80px; height:auto;" />
            </div>
            <div class="print-header-center">
                <div style="font-weight:700; font-size: 13px;">SAINT MICHAEL COLLEGE OF CARAGA</div>
                <div style="font-size: 11px;">Brgy. 4, Nasipit, Agusan del Norte, Caraga Region</div>
                <div style="font-size: 11px;">Tel. Nos: (085) 343-2232 / (085) 283-3113</div>
                <div style="font-size: 11px;">www.smccnasipit.edu.ph</div>
            </div>
            <div class="print-header-right">
                <div class="print-header-right-inner">
                    <img src="../assets/img/socotec.jpg" alt="SOCOTEC ISO 9001" style="max-width: 95px; height:auto;" />
                    <img src="../assets/img/pab_ab.png" alt="PAB AB" style="max-width: 80px; height:auto;" onerror="this.style.display='none'" />
                </div>
            </div>
        </div>
    </div>

    <!-- Report Info -->
    <div class="report-info">
        <div class="title-block">
            <strong>CLASSROOM OBSERVATION REPORT</strong><br />
            <strong><?php echo htmlspecialchars($department_display); ?></strong>
        </div>
        <div class="filters-block">
            <strong>Academic Year:</strong> <?php echo htmlspecialchars($academic_year_label); ?><br>
            <strong>Semester:</strong> <?php echo htmlspecialchars($semester_label); ?><br>
            <strong>Teacher:</strong> <?php echo htmlspecialchars($teacher_label); ?>
        </div>
    </div>

    <!-- Report Table -->
    <table class="report-table">
        <colgroup>
            <col class="col-date">
            <col class="col-teacher">
            <col class="col-subject">
            <col class="col-strength">
            <col class="col-improvement">
            <col class="col-recommendation">
            <col class="col-agreement">
            <col class="col-rating">
        </colgroup>
        <thead>
            <tr>
                <th>Date</th>
                <th>Name of Teacher Observed</th>
                <th>Subject/Class Schedule</th>
                <th>Strength</th>
                <th>Areas for Improvement</th>
                <th>Recommendation/s</th>
                <th>Agreement</th>
                <th>Ratings</th>
            </tr>
        </thead>
        <tbody>
            <?php if(!empty($evaluations)): ?>
                <?php foreach($evaluations as $eval): ?>
                <?php
                $rounded = (int) floor($eval['overall_avg']);
                switch ($rounded) {
                    case 5:  $rating_text = 'Excellent'; break;
                    case 4:  $rating_text = 'Very Satisfactory'; break;
                    case 3:  $rating_text = 'Satisfactory'; break;
                    case 2:  $rating_text = 'Below Satisfactory'; break;
                    default: $rating_text = 'Needs Improvement'; break;
                }

                $evaluation_details = $evaluation->getEvaluationDetails($eval['id']);
                $strengths = [];
                $areas_for_improvement = [];
                $recommendations = [];
                $agreements = [];

                while($detail = $evaluation_details->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($detail['comments'])) {
                        $comment = htmlspecialchars($detail['comments']);
                        if (stripos($comment, 'strength') !== false || stripos($comment, 'good') !== false || stripos($comment, 'excellent') !== false) {
                            $strengths[] = $comment;
                        } elseif (stripos($comment, 'improve') !== false || stripos($comment, 'better') !== false || stripos($comment, 'suggestion') !== false) {
                            $areas_for_improvement[] = $comment;
                        } elseif (stripos($comment, 'recommend') !== false) {
                            $recommendations[] = $comment;
                        } elseif (stripos($comment, 'agree') !== false || stripos($comment, 'acknowledge') !== false) {
                            $agreements[] = $comment;
                        } else {
                            $strengths[] = $comment;
                        }
                    }
                }

                if (!empty($eval['strengths'])) {
                    $strengths[] = htmlspecialchars($eval['strengths']);
                }
                if (!empty($eval['improvement_areas'])) {
                    $areas_for_improvement[] = htmlspecialchars($eval['improvement_areas']);
                }
                ?>
                <tr>
                    <td><?php echo date('M. j, Y', strtotime($eval['observation_date'])); ?></td>
                    <td><?php echo htmlspecialchars($eval['teacher_name']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($eval['subject_observed']); ?>
                        <?php if (!empty($eval['observation_type']) && strtolower($eval['observation_type']) !== 'formal'): ?>
                            <br><small><?php echo htmlspecialchars($eval['observation_type']); ?> Observation</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="observation-notes">
                            <?php if(!empty($strengths)): ?>
                                <ul><?php foreach($strengths as $s): ?><li><?php echo $s; ?></li><?php endforeach; ?></ul>
                            <?php else: ?>
                                <em>No specific strengths identified.</em>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="observation-notes">
                            <?php if(!empty($areas_for_improvement)): ?>
                                <ul><?php foreach($areas_for_improvement as $a): ?><li><?php echo $a; ?></li><?php endforeach; ?></ul>
                            <?php else: ?>
                                <em>No specific areas for improvement identified.</em>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="observation-notes">
                            <?php
                            $all_recommendations = $recommendations;
                            if (!empty($eval['recommendations'])) {
                                $all_recommendations[] = htmlspecialchars($eval['recommendations']);
                            }
                            ?>
                            <?php if(!empty($all_recommendations)): ?>
                                <ul><?php foreach($all_recommendations as $r): ?><li><?php echo $r; ?></li><?php endforeach; ?></ul>
                            <?php else: ?>
                                <em>No specific recommendations provided.</em>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="observation-notes">
                            <?php if(!empty($eval['agreement'])): ?>
                                <?php echo htmlspecialchars($eval['agreement']); ?>
                            <?php elseif(!empty($agreements)): ?>
                                <ul><?php foreach($agreements as $ag): ?><li><?php echo $ag; ?></li><?php endforeach; ?></ul>
                            <?php else: ?>
                                <em>No specific agreements recorded.</em>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="ratings-cell">
                            <span class="rating-score"><?php echo number_format($eval['overall_avg'], 1); ?></span>
                            <span class="rating-label"><?php echo $rating_text; ?></span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="no-data">No evaluations found for the selected filters.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Signature -->
    <?php
    $deanPrintedName = '';
    $deanSignature = '';
    if (is_array($deanPrintEvaluation)) {
        $deanPrintedName = trim((string)($deanPrintEvaluation['rater_printed_name'] ?? ''));
        if ($deanPrintedName === '') {
            $deanPrintedName = trim((string)($deanPrintEvaluation['evaluator_name'] ?? ''));
        }
        $deanSignature = trim((string)($deanPrintEvaluation['rater_signature'] ?? ''));
    }
    ?>
    <?php if ($deanPrintEvaluation !== null): ?>
        <div class="print-signature-block">
            <div class="print-signature-card">
                <div style="font-size: 10px; text-align: left; margin-bottom: 2px;">Prepared by:</div>
                <div class="print-signature-image-wrap">
                    <?php if ($deanSignature !== '' && strpos($deanSignature, 'data:image/') === 0): ?>
                        <img src="<?php echo htmlspecialchars($deanSignature); ?>" alt="Dean observer signature" class="print-signature-image" />
                    <?php endif; ?>
                </div>
                <div class="print-signature-line">
                    <?php echo htmlspecialchars($deanPrintedName !== '' ? $deanPrintedName : ''); ?>
                </div>
                <div class="print-signature-role">Dean</div>
            </div>
        </div>
    <?php endif; ?>
    </div><!-- /.print-page -->

    <script>
        // Auto-print if requested via query param
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_print') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>
