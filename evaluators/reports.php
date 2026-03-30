<?php
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
$raw_department = $is_leader ? (string)($_GET['department'] ?? '') : (string)($_SESSION['department'] ?? '');
$department_display = $department_map[$raw_department] ?? ($raw_department ?: 'All Departments');

$scoped_evaluator_id = in_array($_SESSION['role'], ['dean', 'principal', 'president', 'vice_president'], true)
    ? null
    : ($_SESSION['user_id'] ?? null);

// Build Academic Year list based on actual evaluations (so dropdown only shows years with data)
$available_years = [];
$available_teachers = [];
try {
    if ($is_leader && $raw_department === '') {
        // Leaders with no department filter: show all years and teachers
        $yearsQuery = "SELECT DISTINCT academic_year FROM evaluations WHERE academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC";
        $yearsStmt = $db->prepare($yearsQuery);
        $yearsStmt->execute();
        $available_years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

        $teachersQuery = "SELECT DISTINCT t.id, t.name FROM evaluations e INNER JOIN teachers t ON e.teacher_id = t.id ORDER BY t.name ASC";
        $teachersStmt = $db->prepare($teachersQuery);
        $teachersStmt->execute();
        $available_teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
    $yearsQuery = "SELECT DISTINCT e.academic_year
        FROM evaluations e
        INNER JOIN teachers t ON e.teacher_id = t.id
        WHERE (t.department = :department OR e.evaluator_id = :current_user_id)
          AND e.academic_year IS NOT NULL
          AND e.academic_year <> ''";
    if ($scoped_evaluator_id !== null) {
        $yearsQuery .= " AND e.evaluator_id = :evaluator_id";
    }
    $yearsQuery .= " ORDER BY e.academic_year DESC";

    $yearsStmt = $db->prepare($yearsQuery);
    $yearsStmt->bindValue(':department', $raw_department);
    $yearsStmt->bindValue(':current_user_id', $_SESSION['user_id']);
    if ($scoped_evaluator_id !== null) {
        $yearsStmt->bindValue(':evaluator_id', $scoped_evaluator_id);
    }
    $yearsStmt->execute();
    $available_years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

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
// Leaders see all departments; others see their own department + cross-dept evaluations
$report_department = $is_leader ? $raw_department : '';
$evaluationsStmt = $evaluation->getEvaluationsForReport($scoped_evaluator_id, $academic_year, $semester, $teacher_id, $report_department, $is_leader ? null : $_SESSION['user_id'], $is_leader ? '' : $raw_department, $form_type_filter);
$evaluations = $evaluationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$deanPrintEvaluation = null;
foreach ($evaluations as $evaluationRow) {
    $role = strtolower(trim((string)($evaluationRow['evaluator_role'] ?? '')));
    if ($role === 'dean') {
        $deanPrintEvaluation = $evaluationRow;
        break;
    }
}

// Calculate statistics (include cross-department evaluations by this user)
$stats = $evaluation->getDepartmentStats($is_leader ? ($raw_department ?: '%') : $_SESSION['department'], $academic_year, $semester, $is_leader ? null : $_SESSION['user_id']);
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
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)
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
                            <option value="">All Departments</option>
                            <?php foreach ($department_map as $code => $name): ?>
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
                
                <!-- Report Table -->
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th width="10%">Date</th>
                                <th width="10%">Name of Teacher Observed</th>
                                <th width="10%">Subject/Class Schedule</th>
                                <th width="20%">Strength</th>
                                <th width="15%">Areas for Improvement</th>
                                <th width="15%">Recommendation/s</th>
                                <th width="10%">Agreement</th>
                                <th width="10%">Ratings</th>
                                <th width="8%" class="no-print">Form Type</th>
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
                                        <?php echo htmlspecialchars($eval['teacher_name']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($eval['subject_observed']); ?><br>
                                        <?php if (!empty($eval['observation_type']) && strtolower($eval['observation_type']) !== 'formal'): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($eval['observation_type']); ?> Observation</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="observation-notes">
                                            <!-- Strengths Section -->
                                            <?php if(!empty($strengths)): ?>
                                                <ul>
                                                    <?php foreach($strengths as $strength): ?>
                                                        <li><?php echo $strength; ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <em>No specific strengths identified.</em>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="observation-notes">
                                            <!-- Areas for Improvement Section -->
                                            <?php if(!empty($areas_for_improvement)): ?>
                                                <ul>
                                                    <?php foreach($areas_for_improvement as $area): ?>
                                                        <li><?php echo $area; ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <em>No specific areas for improvement identified.</em>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="observation-notes">
                                            <!-- Recommendations Section -->
                                            <?php if(!empty($recommendations) || !empty($eval['recommendations'])): ?>
                                                <?php 
                                                $all_recommendations = $recommendations;
                                                if (!empty($eval['recommendations'])) {
                                                    $all_recommendations[] = htmlspecialchars($eval['recommendations']);
                                                }
                                                ?>
                                                <ul>
                                                    <?php foreach($all_recommendations as $recommendation): ?>
                                                        <li><?php echo $recommendation; ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <em>No specific recommendations provided.</em>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="observation-notes">
                                            <!-- Agreement Section -->
                                            <?php if(!empty($eval['agreement'])): ?>
                                                <?php echo htmlspecialchars($eval['agreement']); ?>
                                            <?php elseif(!empty($agreements)): ?>
                                                <ul>
                                                    <?php foreach($agreements as $agreement): ?>
                                                        <li><?php echo $agreement; ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <em>No specific agreements recorded.</em>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="ratings-cell">
                                            <span class="rating-score"><?php echo number_format($eval['overall_avg'], 1); ?></span>
                                            <span class="rating-badge rating-label <?php echo $rating_class; ?>">
                                                <?php echo $rating_text; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="no-print text-center">
                                        <?php $ft = $eval['evaluation_form_type'] ?? 'iso'; ?>
                                        <span class="badge <?php echo $ft === 'peac' ? 'bg-success' : 'bg-primary'; ?>"><?php echo strtoupper($ft); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
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
                            <div class="print-signature-role">Dean</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function openPrintReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('auto_print', '1');
            window.open('reports_print.php?' + params.toString(), '_blank');
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