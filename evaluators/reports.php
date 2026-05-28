<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Evaluation.php';
require_once '../models/Teacher.php';
require_once '../includes/program_assignments.php';

$database = new Database();
$db = $database->getConnection();

$evaluation = new Evaluation($db);
$teacher = new Teacher($db);

// Map department codes/names to their full display names for printing.
// Add/adjust entries as needed to match your database values.
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
$is_department_head = in_array($_SESSION['role'], ['dean', 'principal']);
$is_coordinator = in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator']);
$all_departments = array_keys($department_map);
$session_department = trim((string)($_SESSION['department'] ?? ''));
$requested_department = trim((string)($_GET['department'] ?? ''));
$available_filter_departments = [];
if ($is_leader) {
    $available_filter_departments = $all_departments;
    $raw_department = in_array($requested_department, $available_filter_departments, true) ? $requested_department : '';
} elseif ($is_coordinator) {
    $programs = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $session_department);
    foreach ($programs as $p) {
        $p = trim((string)$p);
        if (in_array($p, $all_departments, true) && !in_array($p, $available_filter_departments, true)) {
            $available_filter_departments[] = $p;
        }
    }
    if (empty($available_filter_departments) && $session_department !== '' && in_array($session_department, $all_departments, true)) {
        $available_filter_departments[] = $session_department;
    }
    // Hard guard: block manual URL edits to departments outside assigned programs.
    $raw_department = guardCoordinatorDepartment($requested_department, $available_filter_departments, $session_department);
} else {
    $available_filter_departments = $session_department !== '' ? [$session_department] : [];
    $raw_department = $session_department;
}
$department_display = $department_map[$raw_department] ?? ($raw_department ?: 'All Departments');

// Report should include all observer/evaluator entries within the selected scope
// so schedules show complete observer comments.
$scoped_evaluator_id = null;
$report_scope_observer_id = $is_coordinator ? (int)($_SESSION['user_id'] ?? 0) : null;
$coordinator_report_scope_sql = "";
if ($report_scope_observer_id) {
    $coordinator_report_scope_sql = " AND (
        e.evaluator_id = :scope_observer_user_id
        OR EXISTS (
            SELECT 1
            FROM evaluations se
            WHERE se.teacher_id = e.teacher_id
              AND se.academic_year = e.academic_year
              AND se.semester = e.semester
              AND DATE(se.observation_date) = DATE(e.observation_date)
              AND COALESCE(se.observation_time, '') = COALESCE(e.observation_time, '')
              AND se.evaluator_id = :scope_observer_user_id_eval
        )
        OR EXISTS (
            SELECT 1
            FROM teacher_assignments ta
            WHERE ta.teacher_id = e.teacher_id
              AND ta.evaluator_id = :scope_observer_user_id_assign
              AND (
                  ta.eval_id = e.id
                  OR ta.eval_id IN (
                      SELECT se2.id
                      FROM evaluations se2
                      WHERE se2.teacher_id = e.teacher_id
                        AND se2.academic_year = e.academic_year
                        AND se2.semester = e.semester
                        AND DATE(se2.observation_date) = DATE(e.observation_date)
                        AND COALESCE(se2.observation_time, '') = COALESCE(e.observation_time, '')
                  )
              )
        )
    )";
}
$bind_report_scope = static function(PDOStatement $stmt) use ($report_scope_observer_id): void {
    if (!$report_scope_observer_id) return;
    $stmt->bindValue(':scope_observer_user_id', $report_scope_observer_id, PDO::PARAM_INT);
    $stmt->bindValue(':scope_observer_user_id_eval', $report_scope_observer_id, PDO::PARAM_INT);
    $stmt->bindValue(':scope_observer_user_id_assign', $report_scope_observer_id, PDO::PARAM_INT);
};

// Build Academic Year list based on actual evaluations (so dropdown only shows years with data)
$available_years = [];
$available_teachers = [];
try {
    if ($is_leader && $raw_department === '') {
        // Leaders with no department filter: show all years and teachers
        $yearsQuery = "SELECT DISTINCT academic_year
                       FROM evaluations
                         WHERE academic_year IS NOT NULL
                         AND academic_year <> ''
                         AND status = 'completed'
                         AND overall_avg IS NOT NULL
                         AND overall_avg > 0
                         $coordinator_report_scope_sql
                       ORDER BY academic_year DESC";
        $yearsStmt = $db->prepare($yearsQuery);
        $bind_report_scope($yearsStmt);
        $yearsStmt->execute();
        $available_years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

        $teachersQuery = "SELECT DISTINCT t.id, t.name
                          FROM evaluations e
                          INNER JOIN teachers t ON e.teacher_id = t.id
                          WHERE e.status = 'completed'
                            AND e.overall_avg IS NOT NULL
                            AND e.overall_avg > 0
                            $coordinator_report_scope_sql
                          ORDER BY t.name ASC";
        $teachersStmt = $db->prepare($teachersQuery);
        $bind_report_scope($teachersStmt);
        $teachersStmt->execute();
        $available_teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
    $yearsQuery = "SELECT DISTINCT e.academic_year
        FROM evaluations e
        WHERE e.department = :department
          AND e.academic_year IS NOT NULL
          AND e.academic_year <> ''
          AND e.status = 'completed'
          AND e.overall_avg IS NOT NULL
          AND e.overall_avg > 0";
    $yearsQuery .= $coordinator_report_scope_sql;
    if ($scoped_evaluator_id !== null) {
        $yearsQuery .= " AND e.evaluator_id = :evaluator_id";
    }
    $yearsQuery .= " ORDER BY e.academic_year DESC";

    $yearsStmt = $db->prepare($yearsQuery);
    $yearsStmt->bindValue(':department', $raw_department);
    $bind_report_scope($yearsStmt);
    if ($scoped_evaluator_id !== null) {
        $yearsStmt->bindValue(':evaluator_id', $scoped_evaluator_id);
    }
    $yearsStmt->execute();
    $available_years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

    $teachersQuery = "SELECT DISTINCT t.id, t.name
        FROM evaluations e
        INNER JOIN teachers t ON e.teacher_id = t.id
        WHERE e.department = :department
          AND e.status = 'completed'
          AND e.overall_avg IS NOT NULL
          AND e.overall_avg > 0";
    $teachersQuery .= $coordinator_report_scope_sql;
    if ($scoped_evaluator_id !== null) {
        $teachersQuery .= " AND e.evaluator_id = :evaluator_id";
    }
    if ($scoped_evaluator_id === null) {
        $teachersQuery .= " AND t.user_id != :exclude_user_id";
    }
    $teachersQuery .= " ORDER BY t.name ASC";

    $teachersStmt = $db->prepare($teachersQuery);
    $teachersStmt->bindValue(':department', $raw_department);
    $bind_report_scope($teachersStmt);
    if ($scoped_evaluator_id !== null) {
        $teachersStmt->bindValue(':evaluator_id', $scoped_evaluator_id);
    }
    if ($scoped_evaluator_id === null) {
        $teachersStmt->bindValue(':exclude_user_id', $_SESSION['user_id']);
    }
    $teachersStmt->execute();
    $available_teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {
    // Fail-safe: keep page working even if query fails
    $available_years = [];
    $available_teachers = [];
}

// Get filter parameters
// IMPORTANT: filters should be applied only when explicitly selected.
$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$teacher_id = trim((string)($_GET['teacher_id'] ?? ''));
$form_type_filter = trim((string)($_GET['form_type'] ?? ''));

// Display labels used in the report header
$academic_year_label = ($academic_year !== '') ? $academic_year : 'All';
$semester_label = ($semester !== '') ? $semester : 'All';
$teacher_label = 'All Teachers';
foreach ($available_teachers as $teacher_option) {
    if ((string)($teacher_option['id'] ?? '') === $teacher_id) {
        $teacher_label = (string)($teacher_option['name'] ?? 'All Teachers');
        break;
    }
}

// Get evaluations for reporting
// Department filter MUST be applied to all users to prevent cross-department visibility
// Leaders see all evaluations in the selected department
// Non-leaders see only their own evaluations in their department
$report_department = $raw_department;
// Exclude the current user from appearing as an observed teacher (dean/principal see department reports)
$exclude_self = ($scoped_evaluator_id === null) ? $_SESSION['user_id'] : null;
$evaluationsStmt = $evaluation->getEvaluationsForReport($scoped_evaluator_id, $academic_year, $semester, $teacher_id, $report_department, null, '', $form_type_filter, $exclude_self, $report_scope_observer_id);
$evaluations = $evaluationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$format_day_time = static function(array $eval): string {
    $obsDate = trim((string)($eval['observation_date'] ?? ''));
    if ($obsDate === '') return '';
    $day = date('D', strtotime($obsDate));
    $subjectObserved = trim((string)($eval['subject_observed'] ?? ''));
    $obsTime = trim((string)($eval['observation_time'] ?? ''));
    $start = '';
    $end = '';
    if ($obsTime !== '' && $obsTime !== '00:00' && $obsTime !== '00:00:00') {
        $start = date('g:i A', strtotime($obsTime));
    }
    if (preg_match('/(\d{1,2}:\d{2}\s*(?:AM|PM))(?:\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM)))?/i', $subjectObserved, $m)) {
        if ($start === '' && !empty($m[1])) $start = strtoupper(trim($m[1]));
        if (!empty($m[2])) $end = strtoupper(trim($m[2]));
    }
    if ($start !== '' && $end !== '') return $day . '<br>' . $start . ' - ' . $end;
    if ($start !== '') return $day . '<br>' . $start;
    return $day;
};

// Deduplicate report rows that represent the same schedule slot.
// Keep the latest evaluation record for the same teacher/date/time/subject/form.
$deduped = [];
foreach ($evaluations as $row) {
    $key = implode('|', [
        (string)($row['teacher_id'] ?? ''),
        (string)($row['observation_date'] ?? ''),
        (string)($row['observation_time'] ?? ''),
        (string)($row['subject_observed'] ?? ''),
        (string)($row['evaluation_form_type'] ?? ''),
    ]);
    $currentId = (int)($row['id'] ?? 0);
    if (!isset($deduped[$key]) || $currentId > (int)($deduped[$key]['id'] ?? 0)) {
        $deduped[$key] = $row;
    }
}
$evaluations = array_values($deduped);

// Teacher signature lookup by evaluation_id.
$report_ack_sig_map = [];
try {
    if (!empty($evaluations)) {
        $evalIds = [];
        foreach ($evaluations as $er) {
            $eid = (int)($er['id'] ?? 0);
            if ($eid > 0) $evalIds[$eid] = true;
        }
        $evalIds = array_keys($evalIds);
        if (!empty($evalIds)) {
            $ph = implode(',', array_fill(0, count($evalIds), '?'));
            $ackStmt = $db->prepare("SELECT evaluation_id, signature FROM observation_plan_acknowledgments WHERE evaluation_id IN ($ph) ORDER BY id DESC");
            $ackStmt->execute($evalIds);
            while ($ar = $ackStmt->fetch(PDO::FETCH_ASSOC)) {
                $aeid = (int)($ar['evaluation_id'] ?? 0);
                if ($aeid > 0 && !isset($report_ack_sig_map[$aeid])) {
                    $report_ack_sig_map[$aeid] = trim((string)($ar['signature'] ?? ''));
                }
            }
        }
    }
} catch (Exception $e) {}

$deanPrintEvaluation = null;
foreach ($evaluations as $evaluationRow) {
    $role = strtolower(trim((string)($evaluationRow['evaluator_role'] ?? '')));
    if ($role === 'dean') {
        $deanPrintEvaluation = $evaluationRow;
        break;
    }
}

// Calculate statistics using the same scoping rule as report rows.
$stats = $evaluation->getDepartmentStats($is_leader ? ($raw_department ?: '%') : $_SESSION['department'], $academic_year, $semester, null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?php echo htmlspecialchars($_SESSION['department']); ?></title>
    <?php include '../includes/header.php'; ?>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }
        .classroom-report {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
        }
        .report-header {
            background: #2c3e50;
            color: white;
            padding: 20px;
            text-align: center;
        }
        .report-title {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .report-subtitle {
            font-size: 1rem;
            margin-bottom: 10px;
        }
        .report-info {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-table th {
            background: #34495e;
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            border: 1px solid #ddd;
        }
        .report-table td {
            padding: 10px 8px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        .report-table th.form-type-col,
        .report-table td.form-type-col {
            white-space: nowrap;
            min-width: 90px;
            text-align: center;
            word-break: normal;
        }
        .report-table td ul {
            margin: 0;
            padding-left: 18px;
        }
        .report-table td li {
            margin-bottom: 4px;
        }
        .report-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .rating-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .rating-excellent { background: #28a745; color: white; }
        .rating-very-satisfactory { background: #17a2b8; color: white; }
        .rating-satisfactory { background: #ffc107; color: black; }
        .rating-below-satisfactory { background: #fd7e14; color: white; }
        .rating-needs-improvement { background: #dc3545; color: white; }
        
        .observation-notes {
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .observation-notes ul {
            margin: 5px 0;
            padding-left: 20px;
        }
        .observation-notes li {
            margin-bottom: 3px;
        }
        
        .section-title {
            font-weight: bold;
            color: #2c3e50;
            margin-top: 10px;
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        
        .print-only {
            display: none;
        }
        .report-cards .report-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .report-card-main {
            min-width: 0;
        }
        .report-card-title {
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
            font-size: 1.1rem;
        }
        .report-card-subtitle {
            color: #4b5563;
            margin-bottom: 0;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            margin-left: 10px;
            padding: 3px 10px;
            border-radius: 999px;
            background: #d1fae5;
            color: #166534;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Prevent signature block from being pushed to a new printed page */
        @media print {
            .avoid-page-break {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
        
        /* Responsive table for smaller screens */
        @media (max-width: 1200px) {
            .table-responsive {
                overflow-x: auto;
            }
            .report-table {
                min-width: 1000px;
            }
        }
        
        @media print {
            @page {
                size: portrait;
                margin: 6mm;
            }
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            .print-hide {
                display: none !important;
            }
            body {
                margin: 0 !important;
                background: #fff !important;
                color: #000 !important;
            }
            .sidebar,
            .sidebar-backdrop,
            .mobile-sidebar-toggle,
            .mobile-sidebar-header,
            .dashboard-topbar,
            .dashboard-bg-layer,
            .d-flex.justify-content-between.align-items-center.mb-4,
            .content-header,
            .page-header {
                display: none !important;
            }
            .main-content,
            .container-fluid {
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
            .classroom-report {
                border: none;
                box-shadow: none;
                margin: 0 !important;
            }
            .report-header {
                background: #2c3e50 !important;
                print-color-adjust: exact;
            }
            .report-table th {
                background: #34495e !important;
                print-color-adjust: exact;
            }
            .table-responsive {
                overflow-x: visible;
            }
            .report-table {
                min-width: auto;
                table-layout: fixed;
                width: 100%;
            }
            .report-table col.col-date { width: 9%; }
            .report-table col.col-teacher { width: 10%; }
            .report-table col.col-subject { width: 7%; }
            .report-table col.col-strength { width: 22%; }
            .report-table col.col-improvement { width: 20%; }
            .report-table col.col-recommendation { width: 18%; }
            .report-table col.col-agreement { width: 7%; }
            .report-table col.col-rating { width: 7%; }

            /* More "paper" look */
            .report-info {
                background: #fff !important;
                padding: 4px 0 4px;
                font-size: 9px;
            }
            .report-table th,
            .report-table td {
                border: 1px solid #000 !important;
            }
            .report-table th {
                background: #fff !important;
                color: #000 !important;
                font-weight: 700;
                font-size: 7px;
                line-height: 1.1;
                padding: 2px 2px;
            }
            .report-table td {
                font-size: 6.5px;
                line-height: 1.1;
                word-break: break-word;
                overflow-wrap: anywhere;
                padding: 2px 2px;
                hyphens: auto;
                vertical-align: top;
            }
            .report-table th:first-child,
            .report-table td:first-child {
                white-space: nowrap;
                word-break: normal;
                overflow-wrap: normal;
            }
            .observation-notes {
                font-size: 6.5px;
                line-height: 1.1;
            }
            .observation-notes ul {
                margin: 0;
                padding-left: 8px;
            }
            .observation-notes li {
                margin-bottom: 0;
            }
            .report-table td small {
                font-size: 6px !important;
                line-height: 1.05;
            }
            .classroom-report {
                padding-bottom: 8px;
            }
            .print-signature-block {
                display: flex !important;
                justify-content: flex-start;
                margin-top: 8px;
                padding-top: 4px;
            }
            .print-signature-card {
                width: 260px;
                text-align: center;
            }
            .print-signature-image-wrap {
                height: 46px;
                display: flex;
                align-items: flex-end;
                justify-content: center;
                margin-bottom: 2px;
                overflow: hidden;
            }
            .print-signature-image {
                max-width: 100%;
                max-height: 42px;
                object-fit: contain;
            }
            .print-signature-line {
                border-top: 1px solid #000;
                padding-top: 3px;
                font-size: 11px;
                font-weight: 600;
            }
            .print-signature-role {
                font-size: 10px;
                font-weight: 600;
                margin-top: 1px;
            }
        }
        
        .stat-card {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filters-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 20px;
        }

        /* Ratings cell layout (screen + print)
           Target print structure like the paper form: "4.0  Very Satisfactory" */
        .ratings-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        .ratings-cell .rating-score {
            font-weight: 700;
            min-width: 2.7rem;
            text-align: left;
        }
        .ratings-cell .rating-label {
            font-weight: 600;
            text-align: left;
            white-space: normal;
            line-height: 1.1;
        }

        @media print {
            /* Print like the paper form: plain text, no colored badge */
            .rating-badge {
                background: none !important;
                color: #000 !important;
                padding: 0 !important;
                border-radius: 0 !important;
                font-size: 0.9rem !important;
                font-weight: 600 !important;
            }
            .ratings-cell {
                justify-content: flex-start;
            }
        }

        @media (max-width: 991.98px) {
            .filters-card form .col-md-3,
            .filters-card form .col-md-4,
            .filters-card form .col-md-2 {
                width: 50%;
            }

            .d-flex.justify-content-between.align-items-center.mb-4 {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.75rem;
            }
        }

        @media (max-width: 767.98px) {
            .report-header,
            .report-info,
            .filters-card {
                padding: 1rem;
            }

            .filters-card form .col-md-3,
            .filters-card form .col-md-4,
            .filters-card form .col-md-2 {
                width: 100%;
            }

            .report-table {
                min-width: 960px;
            }
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
                <div class="no-print d-flex gap-2">
                    <button class="btn btn-primary" onclick="openPrintReport()">
                        <i class="fas fa-print me-2"></i>Print Report
                    </button>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#evalFormModal">
                        <i class="fas fa-file-alt me-2"></i>Print Evaluation Form
                    </button>
                </div>
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="evaluatorMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="evaluatorMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <!-- Filters -->
            <div class="filters-card no-print">
                <form method="GET" class="row g-3">
                    <?php if ($is_leader): ?>
                    <div class="col-md-2">
                        <label for="department" class="form-label">Department</label>
                        <select class="form-select" id="department" name="department">
                            <?php if ($is_leader): ?>
                            <option value="">All Departments</option>
                            <?php endif; ?>
                            <?php foreach (($is_leader ? $all_departments : $available_filter_departments) as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $raw_department === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-<?php echo $is_leader ? '2' : '3'; ?>">
                        <label for="academic_year" class="form-label">Academic Year</label>
                        <select class="form-select" id="academic_year" name="academic_year">
                            <option value="" <?php echo empty($academic_year) ? 'selected' : ''; ?>>All Years</option>
                            <option value="2025-2026" <?php echo $academic_year === '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                            <option value="2026-2027" <?php echo $academic_year === '2026-2027' ? 'selected' : ''; ?>>2026-2027</option>
                            <option value="2027-2028" <?php echo $academic_year === '2027-2028' ? 'selected' : ''; ?>>2027-2028</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="semester" class="form-label">Semester</label>
                        <select class="form-select" id="semester" name="semester">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo $semester == '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $semester == '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="teacher_id" class="form-label">Teacher</label>
                        <select class="form-select" id="teacher_id" name="teacher_id">
                            <option value="">All Teachers</option>
                            <?php foreach($available_teachers as $teacher_row): ?>
                                <option value="<?php echo htmlspecialchars((string)$teacher_row['id']); ?>" 
                                    <?php echo $teacher_id == (string)$teacher_row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher_row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="form_type" class="form-label">Form Type</label>
                        <select class="form-select" id="form_type" name="form_type">
                            <option value="" <?php echo $form_type_filter === '' ? 'selected' : ''; ?>>All Forms</option>
                            <option value="iso" <?php echo $form_type_filter === 'iso' ? 'selected' : ''; ?>>ISO</option>
                            <?php if (($_SESSION['department'] ?? '') === 'JHS' || $is_leader): ?>
                            <option value="peac" <?php echo $form_type_filter === 'peac' ? 'selected' : ''; ?>>PEAC</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i> Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- Classroom Observation Report -->
            <div class="classroom-report">                
                <!-- Print Header (matches paper form style) -->
                <div class="print-only" style="padding: 8px 0 10px; border-bottom: 1px solid #000; margin-bottom: 10px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap: 10px;">
                        <!-- Use equal side widths so the center block is truly centered on the page -->
                        <div style="width: 170px; text-align:left;">
                            <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC" style="max-width: 80px; height:auto;" />
                        </div>
                        <div style="flex:1; text-align:center; line-height: 1.2;">
                            <div style="font-weight:700; font-size: 13px;">SAINT MICHAEL COLLEGE OF CARAGA</div>
                            <div style="font-size: 11px;">Brgy. 4, Nasipit, Agusan del Norte, Caraga Region</div>
                            <div style="font-size: 11px;">Tel. Nos: (085) 343-2232 / (085) 283-3113</div>
                            <div style="font-size: 11px;">www.smccnasipit.edu.ph</div>
                            
                        </div>
                        <div style="width: 170px; text-align:right;">
                            <div style="display:flex; gap: 8px; justify-content:flex-end; align-items:center;">
                                    <img src="../assets/img/socotec.jpg" alt="SOCOTEC ISO 9001" style="max-width: 95px; height:auto;" />
                                <img src="../assets/img/pab_ab.png" alt="PAB AB" style="max-width: 80px; height:auto;" onerror="this.style.display='none'" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Info -->
                <div class="report-info">
                    <div class="row">
                        <div class="col-12 text-center">
                            <!-- Show these only when printing (Ctrl+P) -->
                            <div class="print-only">
                                <strong>CLASSROOM OBSERVATION REPORT</strong><br />
                                <strong><?php echo htmlspecialchars($department_display); ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 text-end">
                            <strong>Academic Year:</strong> <?php echo htmlspecialchars($academic_year_label); ?><br>
                            <strong>Semester:</strong> <?php echo htmlspecialchars($semester_label); ?><br>
                            <strong>Teacher:</strong> <?php echo htmlspecialchars($teacher_label); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Screen Cards -->
                <div class="report-cards no-print">
                    <?php if(!empty($evaluations)): ?>
                        <?php foreach($evaluations as $eval): ?>
                            <?php
                                $eid = (int)($eval['id'] ?? 0);
                                $tsig = trim((string)($report_ack_sig_map[$eid] ?? ''));
                                if ($tsig === '') {
                                    $tsig = trim((string)($eval['faculty_signature'] ?? ''));
                                }
                            ?>
                            <div class="report-card">
                                <div class="report-card-main">
                                    <div class="report-card-title">
                                        <i class="fas fa-calendar-alt me-2"></i>Observation Date: <?php echo date('F j, Y', strtotime($eval['observation_date'])); ?>
                                        <span class="status-pill"><i class="fas fa-check-circle me-1"></i>Completed</span>
                                    </div>
                                    <p class="report-card-subtitle mb-1">
                                        <i class="fas fa-user-friends me-2"></i>Teacher Observed: <?php echo htmlspecialchars((string)($eval['teacher_name'] ?? 'N/A')); ?>
                                    </p>
                                    <p class="report-card-subtitle">
                                        <i class="fas fa-book me-2"></i>Subject/Class Schedule: <?php echo htmlspecialchars((string)($eval['subject_observed'] ?? 'N/A')); ?>
                                        <?php
                                            $card_start_raw = trim((string)($eval['observation_start_time'] ?? ''));
                                            $card_end_raw = trim((string)($eval['observation_end_time'] ?? ''));
                                            if ($card_start_raw === '') {
                                                $card_start_raw = trim((string)($eval['observation_time'] ?? ''));
                                            }
                                            $card_time_text = '';
                                            if ($card_start_raw !== '' && $card_end_raw !== '') {
                                                $card_time_text = date('g:i A', strtotime($card_start_raw)) . ' - ' . date('g:i A', strtotime($card_end_raw));
                                            } elseif ($card_start_raw !== '') {
                                                $card_time_text = date('g:i A', strtotime($card_start_raw));
                                            }
                                            if ($card_time_text !== '') {
                                                echo ' (' . htmlspecialchars($card_time_text) . ')';
                                            }
                                        ?>
                                    </p>
                                </div>
                                <div>
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        onclick="openReportPreview(this)"
                                        data-eval-id="<?php echo (int)($eval['id'] ?? 0); ?>"
                                        data-observation-focus="<?php echo htmlspecialchars((string)($eval['evaluation_focus'] ?? '')); ?>"
                                        data-observation-date="<?php echo htmlspecialchars(date('m-d-y', strtotime((string)$eval['observation_date']))); ?>"
                                        data-day-time="<?php echo htmlspecialchars(strip_tags((string)$format_day_time($eval))); ?>"
                                        data-subject-area="<?php echo htmlspecialchars((string)($eval['subject_area'] ?? '')); ?>"
                                        data-subject="<?php echo htmlspecialchars((string)($eval['subject_observed'] ?? '')); ?>"
                                        data-room="<?php echo htmlspecialchars((string)($eval['observation_room'] ?? '')); ?>"
                                        data-signature="<?php echo htmlspecialchars($tsig); ?>"
                                        data-teacher-id="<?php echo (int)($eval['teacher_id'] ?? 0); ?>"
                                        data-observation-date-raw="<?php echo htmlspecialchars((string)($eval['observation_date'] ?? '')); ?>"
                                        data-observation-time-raw="<?php echo htmlspecialchars((string)($eval['observation_time'] ?? '')); ?>"
                                    >
                                        <i class="fas fa-eye me-1"></i>View
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="report-card">
                            <div class="report-card-main">
                                <h5 class="mb-1">No Evaluation Data</h5>
                                <p class="text-muted mb-0">No evaluations found for the selected academic year / semester / teacher.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Print Table -->
                <div class="table-responsive print-only">
                    <table class="report-table">
                        <thead>
                            <tr>
                                    <th width="18%">Date</th>
                                    <th width="26%">Teacher Observer</th>
                                    <th width="46%">Subject/Class Schedule</th>
                                    <th width="10%" class="text-center no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($evaluations)): ?>
                                <?php foreach($evaluations as $eval): ?>
                                <?php
                                // Get rating text and class
                                // Round the average to nearest whole number and map directly to the
                                // simple 1‑5 rating scale used in the legend. This avoids the
                                // confusion caused by arbitrary decimal cutoffs and ensures that
                                // an average of 3.8 (which rounds to 4) is treated the same as a
                                // raw 4.0.
                                $rounded = (int) floor($eval['overall_avg']);
                                switch ($rounded) {
                                    case 5:
                                        $rating_text = 'Excellent';
                                        $rating_class = 'rating-excellent';
                                        break;
                                    case 4:
                                        $rating_text = 'Very Satisfactory';
                                        $rating_class = 'rating-very-satisfactory';
                                        break;
                                    case 3:
                                        $rating_text = 'Satisfactory';
                                        $rating_class = 'rating-satisfactory';
                                        break;
                                    case 2:
                                        $rating_text = 'Below Satisfactory';
                                        $rating_class = 'rating-below-satisfactory';
                                        break;
                                    default:
                                        $rating_text = 'Needs Improvement';
                                        $rating_class = 'rating-needs-improvement';
                                        break;
                                }
                                
                                // Get evaluation details for observations
                                $evaluation_details = $evaluation->getEvaluationDetails($eval['id']);
                                $strengths = [];
                                $areas_for_improvement = [];
                                $recommendations = [];
                                $agreements = [];
                                
                                while($detail = $evaluation_details->fetch(PDO::FETCH_ASSOC)) {
                                    if (!empty($detail['comments'])) {
                                        // Categorize comments based on content or use default logic
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
                                            // Default to strengths for general positive comments
                                            $strengths[] = $comment;
                                        }
                                    }
                                }
                                
                                // Get predefined strengths and areas for improvement from evaluation
                                if (!empty($eval['strengths'])) {
                                    $strengths[] = htmlspecialchars($eval['strengths']);
                                }
                                if (!empty($eval['improvement_areas'])) {
                                    $areas_for_improvement[] = htmlspecialchars($eval['improvement_areas']);
                                }
                                ?>
                                <tr>
                                    <td><?php echo date('F j, Y', strtotime($eval['observation_date'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars((string)($eval['evaluator_name'] ?? 'N/A')); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($eval['subject_observed']); ?><br>
                                        <small class="text-muted"><?php echo $format_day_time($eval); ?></small><br>
                                        <?php if (!empty($eval['observation_type']) && strtolower($eval['observation_type']) !== 'formal'): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($eval['observation_type']); ?> Observation</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center no-print">
                                        <?php
                                            $eid = (int)($eval['id'] ?? 0);
                                            $tsig = trim((string)($report_ack_sig_map[$eid] ?? ''));
                                            if ($tsig === '') {
                                                $tsig = trim((string)($eval['faculty_signature'] ?? ''));
                                            }
                                        ?>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary"
                                            onclick="openReportPreview(this)"
                                            data-eval-id="<?php echo (int)($eval['id'] ?? 0); ?>"
                                            data-observation-focus="<?php echo htmlspecialchars((string)($eval['evaluation_focus'] ?? '')); ?>"
                                            data-observation-date="<?php echo htmlspecialchars(date('m-d-y', strtotime((string)$eval['observation_date']))); ?>"
                                            data-day-time="<?php echo htmlspecialchars(strip_tags((string)$format_day_time($eval))); ?>"
                                            data-subject-area="<?php echo htmlspecialchars((string)($eval['subject_area'] ?? '')); ?>"
                                            data-subject="<?php echo htmlspecialchars((string)($eval['subject_observed'] ?? '')); ?>"
                                            data-room="<?php echo htmlspecialchars((string)($eval['observation_room'] ?? '')); ?>"
                                            data-signature="<?php echo htmlspecialchars($tsig); ?>"
                                            data-teacher-id="<?php echo (int)($eval['teacher_id'] ?? 0); ?>"
                                            data-observation-date-raw="<?php echo htmlspecialchars((string)($eval['observation_date'] ?? '')); ?>"
                                            data-observation-time-raw="<?php echo htmlspecialchars((string)($eval['observation_time'] ?? '')); ?>"
                                        >
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i>
                                        <h5>No Evaluation Data</h5>
                                        <p class="text-muted">No evaluations found for the selected academic year / semester / teacher.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                
                <!-- Print Signature (Prepared by) -->
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
                    <div class="print-only avoid-page-break print-signature-block">
                        <div class="print-signature-card">
                            <div style="font-size: 12px; text-align: left; margin-bottom: 4px;">Prepared by:</div>
                            <div class="print-signature-image-wrap">
                                <?php if ($deanSignature !== '' && strpos($deanSignature, 'data:image/') === 0): ?>
                                    <img src="<?php echo htmlspecialchars($deanSignature); ?>" alt="Dean observer signature" class="print-signature-image" />
                                <?php endif; ?>
                            </div>
                            <div class="print-signature-line">
                                <?php echo htmlspecialchars($deanPrintedName !== '' ? $deanPrintedName : ''); ?>
                            </div>
                            <div class="print-signature-role"><?php echo htmlspecialchars('Dean' . (!empty($raw_department) ? ', ' . $raw_department : '')); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        let reportSignatureModal = null;
        let reportSigCanvas = null;
        let reportSigCtx = null;
        let reportSigDrawing = false;
        let reportSigTouched = false;
        let currentPreviewData = null;
        let currentPreviewEvalId = 0;

        function initReportSignaturePad() {
            reportSigCanvas = document.getElementById('reportSignatureCanvas');
            if (!reportSigCanvas) return;
            reportSigCtx = reportSigCanvas.getContext('2d');
            reportSigCtx.lineWidth = 2;
            reportSigCtx.lineCap = 'round';
            reportSigCtx.strokeStyle = '#111';
            clearReportSignature();
            bindReportSignatureEvents();
        }

        function getReportSigPoint(e) {
            const rect = reportSigCanvas.getBoundingClientRect();
            if (e.touches && e.touches.length > 0) {
                return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
            }
            return { x: e.clientX - rect.left, y: e.clientY - rect.top };
        }

        function bindReportSignatureEvents() {
            if (!reportSigCanvas) return;
            const start = function(e) {
                e.preventDefault();
                reportSigDrawing = true;
                reportSigTouched = true;
                const p = getReportSigPoint(e);
                reportSigCtx.beginPath();
                reportSigCtx.moveTo(p.x, p.y);
            };
            const move = function(e) {
                if (!reportSigDrawing) return;
                e.preventDefault();
                const p = getReportSigPoint(e);
                reportSigCtx.lineTo(p.x, p.y);
                reportSigCtx.stroke();
            };
            const end = function() { reportSigDrawing = false; };
            reportSigCanvas.addEventListener('mousedown', start);
            reportSigCanvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            reportSigCanvas.addEventListener('touchstart', start, { passive: false });
            reportSigCanvas.addEventListener('touchmove', move, { passive: false });
            window.addEventListener('touchend', end);
        }

        function clearReportSignature() {
            if (!reportSigCanvas || !reportSigCtx) return;
            reportSigCtx.clearRect(0, 0, reportSigCanvas.width, reportSigCanvas.height);
            reportSigCtx.fillStyle = '#ffffff';
            reportSigCtx.fillRect(0, 0, reportSigCanvas.width, reportSigCanvas.height);
            reportSigTouched = false;
        }

        function openPrintReport() {
            if (!reportSignatureModal) {
                const el = document.getElementById('printSignatureModal');
                reportSignatureModal = new bootstrap.Modal(el);
                el.addEventListener('shown.bs.modal', function() {
                    initReportSignaturePad();
                    const printedName = document.getElementById('reportPrintedName');
                    if (printedName && !printedName.value.trim()) {
                        printedName.value = <?php echo json_encode((string)($_SESSION['name'] ?? '')); ?>;
                    }
                });
            }
            reportSignatureModal.show();
        }

        function submitReportSignatureAndPrint() {
            const printedName = (document.getElementById('reportPrintedName').value || '').trim();
            if (!reportSigTouched) {
                alert('Please provide your signature first.');
                return;
            }
            if (!printedName) {
                alert('Please enter printed name.');
                return;
            }

            const signatureData = reportSigCanvas.toDataURL('image/png');
            const formData = new FormData();
            formData.append('signature_data', signatureData);
            formData.append('printed_name', printedName);

            fetch('save_report_signature.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (!res || !res.ok) {
                        throw new Error((res && res.message) ? res.message : 'Failed to save signature.');
                    }
                    if (reportSignatureModal) reportSignatureModal.hide();
                    const params = new URLSearchParams(window.location.search);
                    params.set('auto_print', '1');
                    window.open('reports_print.php?' + params.toString(), '_blank');
                })
                .catch(err => {
                    alert(err.message || 'Unable to continue printing.');
                });
        }

        function openReportPreview(btn) {
            currentPreviewEvalId = parseInt(btn.dataset.evalId || '0', 10) || 0;
            const focus = (btn.dataset.observationFocus || '').trim();
            const focusHtml = focus
                ? focus.split(',').map(item => `<div>${escapeHtml(item.trim())}</div>`).join('')
                : '<div class="text-muted">N/A</div>';
            const dayTime = (btn.dataset.dayTime || '').replace(/\s+/g, ' ').trim();
            currentPreviewData = {
                focus: focus,
                date: btn.dataset.observationDate || 'N/A',
                dayTime: dayTime || 'N/A',
                subjectArea: btn.dataset.subjectArea || 'N/A',
                subject: btn.dataset.subject || 'N/A',
                room: btn.dataset.room || 'N/A',
                comments: []
            };

            document.getElementById('previewFocus').innerHTML = focusHtml;
            document.getElementById('previewDate').textContent = btn.dataset.observationDate || 'N/A';
            document.getElementById('previewDayTime').textContent = dayTime || 'N/A';
            document.getElementById('previewSubjectArea').textContent = btn.dataset.subjectArea || 'N/A';
            document.getElementById('previewSubject').textContent = btn.dataset.subject || 'N/A';
            document.getElementById('previewRoom').textContent = btn.dataset.room || 'N/A';

            const commentsWrap = document.getElementById('previewObserverComments');
            commentsWrap.innerHTML = '<div class="text-muted">Loading observer comments...</div>';
            const teacherId = btn.dataset.teacherId || '';
            const obsDateRaw = btn.dataset.observationDateRaw || '';
            const obsTimeRaw = btn.dataset.observationTimeRaw || '';
            fetch('report_observer_comments.php?teacher_id=' + encodeURIComponent(teacherId) + '&observation_date=' + encodeURIComponent(obsDateRaw) + '&observation_time=' + encodeURIComponent(obsTimeRaw))
                .then(r => r.json())
                .then(res => {
                    if (!res || !res.ok || !Array.isArray(res.items) || res.items.length === 0) {
                        commentsWrap.innerHTML = '<div class="text-muted">No observer comments found for this schedule.</div>';
                        if (currentPreviewData) currentPreviewData.comments = [];
                        return;
                    }
                    if (currentPreviewData) currentPreviewData.comments = res.items;
                    let html = '';
                    res.items.forEach(item => {
                        const renderList = (arr, emptyTxt) => {
                            if (!arr || arr.length === 0) return '<em class="text-muted">' + emptyTxt + '</em>';
                            return '<ul class="mb-2">' + arr.map(v => '<li>' + escapeHtml(v) + '</li>').join('') + '</ul>';
                        };
                        html += '<div class="border rounded p-3 mb-3">';
                        html += '<div class="fw-bold mb-2">' + escapeHtml(item.observer) + (item.rating ? ' - Rating: ' + escapeHtml(item.rating) : '') + '</div>';
                        html += '<div><strong>Strength</strong>' + renderList(item.strengths, 'No specific strengths identified.') + '</div>';
                        html += '<div><strong>Areas for Improvement</strong>' + renderList(item.improvements, 'No specific areas for improvement identified.') + '</div>';
                        html += '<div><strong>Recommendation/s</strong>' + renderList(item.recommendations, 'No specific recommendations provided.') + '</div>';
                        html += '<div><strong>Agreement</strong>' + renderList(item.agreements, 'No specific agreements recorded.') + '</div>';
                        html += '</div>';
                    });
                    commentsWrap.innerHTML = html;
                })
                .catch(() => {
                    commentsWrap.innerHTML = '<div class="text-danger">Unable to load observer comments.</div>';
                    if (currentPreviewData) currentPreviewData.comments = [];
                });

            loadObservationProofs();

            const modal = new bootstrap.Modal(document.getElementById('reportPreviewModal'));
            modal.show();
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str || ''));
            return div.innerHTML;
        }

        function renderProofs(items) {
            const wrap = document.getElementById('previewProofGallery');
            if (!wrap) return;
            if (!Array.isArray(items) || items.length === 0) {
                wrap.innerHTML = '<div class="text-muted">No documentation uploaded yet.</div>';
                return;
            }
            let html = '<div class="d-flex flex-column gap-2">';
            items.forEach(function(item) {
                const url = escapeHtml(item.url || '');
                const who = escapeHtml(item.uploaded_by || 'Observer');
                const at = escapeHtml(item.uploaded_at || '');
                const fileName = escapeHtml(item.file_name || 'photo');
                const id = parseInt(item.id || 0, 10) || 0;
                const canDelete = !!item.can_delete;
                html += '<div class="d-flex align-items-start justify-content-between gap-2 border rounded p-2 bg-white">';
                html += '<div>';
                html += '<a href="' + url + '" target="_blank" rel="noopener">' + fileName + '</a>';
                html += '<div class="small text-muted" style="line-height:1.2;">Uploaded by ' + who + (at ? ('<br>' + at) : '') + '</div>';
                html += '</div>';
                if (canDelete && id > 0) {
                    html += '<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteObservationProof(' + id + ')">' +
                            '<i class="fas fa-trash me-1"></i>Remove</button>';
                }
                html += '</div>';
            });
            html += '</div>';
            wrap.innerHTML = html;
        }

        function loadObservationProofs() {
            const wrap = document.getElementById('previewProofGallery');
            if (!wrap) return;
            if (!currentPreviewEvalId) {
                wrap.innerHTML = '<div class="text-muted">No evaluation selected.</div>';
                return;
            }
            wrap.innerHTML = '<div class="text-muted">Loading documentation...</div>';
            fetch('observation_proof.php?evaluation_id=' + encodeURIComponent(String(currentPreviewEvalId)))
                .then(r => r.json())
                .then(res => {
                    if (!res || !res.ok) {
                        wrap.innerHTML = '<div class="text-danger">Unable to load documentation.</div>';
                        return;
                    }
                    renderProofs(res.items || []);
                })
                .catch(() => {
                    wrap.innerHTML = '<div class="text-danger">Unable to load documentation.</div>';
                });
        }

        function uploadObservationProof() {
            const fileInput = document.getElementById('previewProofInput');
            const statusEl = document.getElementById('previewProofStatus');
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                if (statusEl) statusEl.textContent = 'Please choose an image first.';
                return;
            }
            if (!currentPreviewEvalId) {
                if (statusEl) statusEl.textContent = 'No evaluation selected.';
                return;
            }
            const fd = new FormData();
            fd.append('evaluation_id', String(currentPreviewEvalId));
            fd.append('proof', fileInput.files[0]);
            if (statusEl) statusEl.textContent = 'Uploading...';

            fetch('observation_proof.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res || !res.ok) {
                        if (statusEl) statusEl.textContent = (res && res.message) ? res.message : 'Upload failed.';
                        return;
                    }
                    fileInput.value = '';
                    if (statusEl) statusEl.textContent = 'Uploaded.';
                    loadObservationProofs();
                })
                .catch(() => {
                    if (statusEl) statusEl.textContent = 'Upload failed.';
                });
        }

        function deleteObservationProof(proofId) {
            const statusEl = document.getElementById('previewProofStatus');
            if (!proofId) return;
            if (!confirm('Remove this uploaded photo?')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('proof_id', String(proofId));
            if (statusEl) statusEl.textContent = 'Removing...';
            fetch('observation_proof.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res || !res.ok) {
                        if (statusEl) statusEl.textContent = (res && res.message) ? res.message : 'Remove failed.';
                        return;
                    }
                    if (statusEl) statusEl.textContent = 'Removed.';
                    loadObservationProofs();
                })
                .catch(() => {
                    if (statusEl) statusEl.textContent = 'Remove failed.';
                });
        }


        // Export functions
        function exportToPDF() {
            // Create a simplified version of the report for PDF export
            const reportContent = document.querySelector('.classroom-report').cloneNode(true);
            
            // Remove no-print elements
            const noPrintElements = reportContent.querySelectorAll('.no-print');
            noPrintElements.forEach(el => el.remove());
            
            // Create a new window for printing
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Classroom Observation Report - <?php echo htmlspecialchars($_SESSION['department']); ?></title>
                    <style>
                        @page { size: portrait; margin: 6mm; }
                        body { font-family: Arial, sans-serif; margin: 0; color: #000; }
                        .classroom-report { border: none; }
                        .report-header { 
                            background: #2c3e50; 
                            color: white; 
                            padding: 20px; 
                            text-align: center; 
                        }
                        .report-title { font-size: 1.5rem; font-weight: bold; }
                        .report-info { background: #fff; padding: 4px 0; border: none; font-size: 9px; }
                        .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
                        .report-table th, .report-table td { border: 1px solid #000; }
                        .report-table th { background: #fff; color: #000; padding: 2px; text-align: left; font-weight: 700; font-size: 7px; }
                        .report-table td { padding: 2px; vertical-align: top; font-size: 6.5px; word-break: break-word; line-height: 1.1; }
                        .observation-notes ul { margin: 0; padding-left: 8px; }
                        .observation-notes li { margin-bottom: 0; }
                        .rating-badge { background: none; color: #000; padding: 0; border-radius: 0; font-weight: 600; }
                        .ratings-cell { display: flex; align-items: center; gap: 4px; }
                        .ratings-cell .rating-score { min-width: 2rem; font-weight: 700; }
                        .section-title { font-weight: bold; margin-top: 8px; margin-bottom: 3px; }
                        @media print { body { margin: 0; } @page { size: portrait; margin: 6mm; } }
                    </style>
                </head>
                <body>
                    ${reportContent.outerHTML}
                </body>
                </html>
            `);
            printWindow.document.close();
            
            // Wait for content to load then print
            printWindow.onload = function() {
                printWindow.print();
            };
        }

        // Auto-print option for direct report generation
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === 'true') {
            window.print();
        }
    </script>

    <div class="modal fade" id="reportPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 960px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Evaluation Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height:72vh; overflow-y:auto;">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Focus of Observation</th>
                                    <th>Date</th>
                                    <th>Day & Time</th>
                                    <th>Subject Area</th>
                                    <th>Subject</th>
                                    <th>Room</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td id="previewFocus"></td>
                                    <td id="previewDate" class="text-center fw-semibold"></td>
                                    <td id="previewDayTime" class="text-center"></td>
                                    <td id="previewSubjectArea"></td>
                                    <td id="previewSubject" class="fw-semibold"></td>
                                    <td id="previewRoom" class="text-center"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        <div class="fw-semibold mb-2">Observer Comments</div>
                        <div id="previewObserverComments" class="bg-light border rounded p-2"></div>
                    </div>
                    <div class="mt-3">
                        <div class="fw-semibold mb-2">Documentation </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <input type="file" id="previewProofInput" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:320px;">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="uploadObservationProof()">
                                <i class="fas fa-upload me-1"></i>Upload
                            </button>
                            <span id="previewProofStatus" class="small text-muted"></span>
                        </div>
                        <div id="previewProofGallery" class="bg-light border rounded p-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="openPrintReport()">
                        <i class="fas fa-print me-1"></i>Print Report
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Signature Modal -->
    <div class="modal fade" id="printSignatureModal" tabindex="-1" aria-labelledby="printSignatureModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 560px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="printSignatureModalLabel"><i class="fas fa-signature me-2"></i>Sign Before Printing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label for="reportPrintedName" class="form-label fw-bold">Printed Name</label>
                    <input type="text" id="reportPrintedName" class="form-control mb-3" value="<?php echo htmlspecialchars((string)($_SESSION['name'] ?? '')); ?>">
                    <label class="form-label fw-bold">Signature</label>
                    <div class="border rounded p-2 bg-light">
                        <canvas id="reportSignatureCanvas" width="500" height="110" style="width:100%; height:110px; background:#fff; border:1px solid #dcdcdc; border-radius:4px;"></canvas>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearReportSignature()">
                            <i class="fas fa-eraser me-1"></i>Clear
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="submitReportSignatureAndPrint()">
                        <i class="fas fa-print me-1"></i>Sign & Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Evaluation Form Selection Modal -->
    <div class="modal fade" id="evalFormModal" tabindex="-1" aria-labelledby="evalFormModalLabel" aria-hidden="true">
        <div class="modal-dialog" style="max-width:900px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="evalFormModalLabel"><i class="fas fa-file-alt me-2"></i>Print Evaluation Form</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Step 1: Choose form type -->
                    <?php if (($_SESSION['department'] ?? '') === 'JHS' || $is_leader): ?>
                    <div id="evalFormStep1">
                        <label class="form-label fw-bold mb-3">Select Form Type</label>
                        <div class="d-flex justify-content-center gap-3">
                            <button type="button" class="btn btn-outline-primary btn-lg px-5 py-3" onclick="selectFormType('iso')">
                                <i class="fas fa-file-alt fa-2x d-block mb-2"></i>ISO Form
                            </button>
                            <button type="button" class="btn btn-outline-success btn-lg px-5 py-3" onclick="selectFormType('peac')">
                                <i class="fas fa-clipboard-check fa-2x d-block mb-2"></i>PEAC Form
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Step 2: Select teacher -->
                    <div id="evalFormStep2" style="<?php echo (($_SESSION['department'] ?? '') !== 'JHS' && !$is_leader) ? '' : 'display:none;'; ?>">
                        <div class="d-flex align-items-center mb-3">
                            <?php if (($_SESSION['department'] ?? '') === 'JHS' || $is_leader): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="backToStep1()">
                                <i class="fas fa-arrow-left"></i>
                            </button>
                            <?php endif; ?>
                            <span class="fw-bold" id="evalFormTypeLabel"><?php echo (($_SESSION['department'] ?? '') !== 'JHS' && !$is_leader) ? '<i class="fas fa-file-alt me-1"></i> ISO Form Evaluations' : ''; ?></span>
                        </div>
                        <div class="mb-3">
                            <label for="evalFormTeacher" class="form-label fw-bold">Select a Teacher</label>
                            <select class="form-select" id="evalFormTeacher">
                                <option value="">-- Choose a teacher --</option>
                            </select>
                        </div>
                        <div id="evalFormList" style="display:none;">
                            <label class="form-label fw-bold">Select an Evaluation</label>
                            <div id="evalFormListBody"></div>
                        </div>
                        <div id="evalFormLoading" style="display:none;" class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading...
                        </div>
                        <div id="evalFormEmpty" style="display:none;" class="text-center py-3 text-muted">
                            No evaluations found.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    var _selectedFormType = '';

    function selectFormType(type) {
        _selectedFormType = type;
        var step1 = document.getElementById('evalFormStep1');
        if (step1) step1.style.display = 'none';
        document.getElementById('evalFormStep2').style.display = 'block';
        document.getElementById('evalFormTypeLabel').innerHTML =
            (type === 'iso' ? '<i class="fas fa-file-alt me-1"></i> ISO Form' : '<i class="fas fa-clipboard-check me-1"></i> PEAC Form') + ' Evaluations';

        // Reset
        var sel = document.getElementById('evalFormTeacher');
        sel.innerHTML = '<option value="">-- Choose a teacher --</option>';
        document.getElementById('evalFormList').style.display = 'none';
        document.getElementById('evalFormListBody').innerHTML = '';
        document.getElementById('evalFormEmpty').style.display = 'none';
        document.getElementById('evalFormLoading').style.display = 'block';

        fetch('../includes/get_teachers_by_form_type.php?form_type=' + encodeURIComponent(type))
            .then(function(r) { return r.json(); })
            .then(function(teachers) {
                document.getElementById('evalFormLoading').style.display = 'none';
                if (!teachers || teachers.length === 0) {
                    document.getElementById('evalFormEmpty').style.display = 'block';
                    document.getElementById('evalFormEmpty').textContent = 'No teachers with ' + type.toUpperCase() + ' evaluations found.';
                    return;
                }
                teachers.forEach(function(t) {
                    var opt = document.createElement('option');
                    opt.value = t.id;
                    opt.textContent = t.name;
                    sel.appendChild(opt);
                });
            })
            .catch(function() {
                document.getElementById('evalFormLoading').style.display = 'none';
                document.getElementById('evalFormEmpty').style.display = 'block';
            });
    }

    function backToStep1() {
        _selectedFormType = '';
        var step1 = document.getElementById('evalFormStep1');
        if (step1) {
            step1.style.display = 'block';
            document.getElementById('evalFormStep2').style.display = 'none';
        }
    }

    // Reset modal on close
    document.getElementById('evalFormModal').addEventListener('hidden.bs.modal', function() {
        <?php if (($_SESSION['department'] ?? '') === 'JHS' || $is_leader): ?>
        backToStep1();
        <?php else: ?>
        // Non-JHS: reset the teacher list but stay on step 2
        _selectedFormType = 'iso';
        var sel = document.getElementById('evalFormTeacher');
        sel.innerHTML = '<option value="">-- Choose a teacher --</option>';
        document.getElementById('evalFormList').style.display = 'none';
        document.getElementById('evalFormListBody').innerHTML = '';
        document.getElementById('evalFormEmpty').style.display = 'none';
        document.getElementById('evalFormLoading').style.display = 'none';
        <?php endif; ?>
    });

    // For non-JHS departments (and non-leaders), auto-select ISO when modal opens
    <?php if (($_SESSION['department'] ?? '') !== 'JHS' && !$is_leader): ?>
    document.getElementById('evalFormModal').addEventListener('shown.bs.modal', function() {
        selectFormType('iso');
    });
    <?php endif; ?>

    document.getElementById('evalFormTeacher').addEventListener('change', function() {
        var teacherId = this.value;
        var listDiv = document.getElementById('evalFormList');
        var listBody = document.getElementById('evalFormListBody');
        var loading = document.getElementById('evalFormLoading');
        var empty = document.getElementById('evalFormEmpty');

        listDiv.style.display = 'none';
        listBody.innerHTML = '';
        empty.style.display = 'none';

        if (!teacherId) return;

        loading.style.display = 'block';

        fetch('../includes/get_teacher_evaluations.php?teacher_id=' + encodeURIComponent(teacherId) + '&form_type=' + encodeURIComponent(_selectedFormType))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loading.style.display = 'none';
                if (!data || data.length === 0) {
                    empty.style.display = 'block';
                    empty.textContent = 'No evaluations found for this teacher.';
                    return;
                }
                var html = '<div class="table-responsive"><table class="table table-bordered table-hover table-sm">';
                html += '<thead><tr><th>Date</th><th>Academic Year</th><th>Semester</th><th>Subject</th><th>Evaluator</th><th>Rating</th><th>Action</th></tr></thead><tbody>';
                data.forEach(function(ev) {
                    html += '<tr>';
                    html += '<td>' + escapeHtml(ev.date) + '</td>';
                    html += '<td>' + escapeHtml(ev.academic_year) + '</td>';
                    html += '<td>' + escapeHtml(ev.semester) + '</td>';
                    html += '<td>' + escapeHtml(ev.subject) + '</td>';
                    html += '<td>' + escapeHtml(ev.evaluator) + '</td>';
                    html += '<td>' + escapeHtml(ev.overall_avg) + '</td>';
                    html += '<td><button class="btn btn-sm btn-primary" onclick="openEvalForm(' + parseInt(ev.id) + ')"><i class="fas fa-print me-1"></i>Print</button></td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
                listBody.innerHTML = html;
                listDiv.style.display = 'block';
            })
            .catch(function() {
                loading.style.display = 'none';
                empty.style.display = 'block';
            });
    });

    function openEvalForm(evalId) {
        var page = (_selectedFormType === 'peac') ? 'print_evaluation_form_peac.php' : 'print_evaluation_form.php';
        window.open(page + '?id=' + evalId + '&auto_print=1', '_blank');
    }
    </script>
    </div>
</body>
</html>

