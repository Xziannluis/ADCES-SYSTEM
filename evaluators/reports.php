<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator'])) {
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
    'CCIS' => 'College of Computing and Information Sciences',
    'COE'  => 'College of Education',
    'CBA'  => 'College of Business Administration',
    'CCJE' => 'College of Criminal Justice Education',
    'CAS'  => 'College of Arts and Sciences',
    'CHM'  => 'College of Hospitality Management',
    'CTE'  => 'College of Teacher Education',
];

$raw_department = (string)($_SESSION['department'] ?? '');
$department_display = $department_map[$raw_department] ?? $raw_department;

// Build Academic Year list based on actual evaluations (so dropdown only shows years with data)
$available_years = [];
try {
    $yearsStmt = $db->prepare(
        "SELECT DISTINCT academic_year FROM evaluations WHERE evaluator_id = :evaluator_id AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC"
    );
    $yearsStmt->bindValue(':evaluator_id', $_SESSION['user_id']);
    $yearsStmt->execute();
    $available_years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Fail-safe: keep page working even if query fails
    $available_years = [];
}

// Get filter parameters
// IMPORTANT: filters should be applied only when explicitly selected.
$academic_year = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$teacher_id = trim((string)($_GET['teacher_id'] ?? ''));

// Display labels used in the report header
$academic_year_label = ($academic_year !== '') ? $academic_year : 'All';
$semester_label = ($semester !== '') ? $semester : 'All';

// Get evaluations for reporting
$evaluations = $evaluation->getEvaluationsForReport($_SESSION['user_id'], $academic_year, $semester, $teacher_id);
$teachers = $teacher->getByDepartment($_SESSION['department']);

// Calculate statistics
$stats = $evaluation->getDepartmentStats($_SESSION['department'], $academic_year, $semester);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?php echo $_SESSION['department']; ?></title>
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
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            .print-hide {
                display: none !important;
            }
            .classroom-report {
                border: none;
                box-shadow: none;
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
            }

            /* More "paper" look */
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .report-info {
                background: #fff !important;
            }
            .report-table th,
            .report-table td {
                border: 1px solid #000 !important;
            }
            .report-table th {
                background: #fff !important;
                color: #000 !important;
                font-weight: 700;
                font-size: 12px;
            }
            .report-table td {
                font-size: 12px;
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
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="print-hide">Evaluation Reports - <?php echo $_SESSION['department']; ?></h3>
                <div class="no-print mt-3">
                    <button class="btn btn-primary me-2" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Report
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card no-print">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="academic_year" class="form-label">Academic Year</label>
                        <select class="form-select" id="academic_year" name="academic_year">
                            <option value="" <?php echo empty($academic_year) ? 'selected' : ''; ?>>All Years</option>
                            <?php if (!empty($available_years)): ?>
                                <?php foreach ($available_years as $yr): ?>
                                    <option value="<?php echo htmlspecialchars($yr); ?>" <?php echo $academic_year == $yr ? 'selected' : ''; ?>><?php echo htmlspecialchars($yr); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php if (!empty($academic_year)): ?>
                                    <option value="<?php echo htmlspecialchars($academic_year); ?>" selected><?php echo htmlspecialchars($academic_year); ?></option>
                                <?php endif; ?>
                            <?php endif; ?>
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
                    <div class="col-md-4">
                        <label for="teacher_id" class="form-label">Teacher</label>
                        <select class="form-select" id="teacher_id" name="teacher_id">
                            <option value="">All Teachers</option>
                            <?php while($teacher_row = $teachers->fetch(PDO::FETCH_ASSOC)): ?>
                            <option value="<?php echo $teacher_row['id']; ?>" 
                                <?php echo $teacher_id == $teacher_row['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher_row['name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
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
                            <img src="../SMCCnewlogo.png" alt="SMCC" style="max-width: 80px; height:auto;" />
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
                            <strong>Semester:</strong> <?php echo htmlspecialchars($semester_label); ?>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($evaluations->rowCount() > 0): ?>
                                <?php while($eval = $evaluations->fetch(PDO::FETCH_ASSOC)): ?>
                                <?php
                                // Get rating text and class
                                $rating_text = 'Needs Improvement';
                                $rating_class = 'rating-needs-improvement';
                                
                                if($eval['overall_avg'] >= 4.6) {
                                    $rating_text = 'Excellent';
                                    $rating_class = 'rating-excellent';
                                } elseif($eval['overall_avg'] >= 3.6) {
                                    $rating_text = 'Very Satisfactory';
                                    $rating_class = 'rating-very-satisfactory';
                                } elseif($eval['overall_avg'] >= 2.9) {
                                    $rating_text = 'Satisfactory';
                                    $rating_class = 'rating-satisfactory';
                                } elseif($eval['overall_avg'] >= 1.8) {
                                    $rating_text = 'Below Satisfactory';
                                    $rating_class = 'rating-below-satisfactory';
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
                                    <td><?php echo htmlspecialchars($eval['teacher_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($eval['subject_observed']); ?><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($eval['observation_type']); ?> Observation</small>
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
                                            <?php if(!empty($agreements)): ?>
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
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
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
                <div class="print-only avoid-page-break" style="margin-top: 24px;">
                    <div style="max-width: 260px;">
                        <div style="font-size: 12px;">Prepared by:</div>
                        <div style="height: 40px;"></div>
                        <div style="border-top: 1px solid #000; font-size: 12px; text-align:center; padding-top: 4px;">
                            <?php echo htmlspecialchars($_SESSION['name'] ?? ''); ?>
                            <?php if (!empty($_SESSION['role'])): ?>
                                <div style="font-size: 11px; font-weight: 600; margin-top: 2px;">(<?php echo htmlspecialchars($_SESSION['role']); ?>)</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
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
                    <title>Classroom Observation Report - <?php echo $_SESSION['department']; ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; color: #000; }
                        .classroom-report { border: 1px solid #ddd; }
                        .report-header { 
                            background: #2c3e50; 
                            color: white; 
                            padding: 20px; 
                            text-align: center; 
                        }
                        .report-title { font-size: 1.5rem; font-weight: bold; }
                        .report-info { background: #fff; padding: 10px 0; border: none; }
                        .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                        .report-table th, .report-table td { border: 1px solid #000; }
                        .report-table th { background: #fff; color: #000; padding: 8px; text-align: left; font-weight: 700; font-size: 12px; }
                        .report-table td { padding: 8px; vertical-align: top; font-size: 12px; }
                        .rating-badge { background: none; color: #000; padding: 0; border-radius: 0; font-weight: 600; }
                        .ratings-cell { display: flex; align-items: center; gap: 8px; }
                        .ratings-cell .rating-score { min-width: 2.7rem; font-weight: 700; }
                        .section-title { font-weight: bold; margin-top: 8px; margin-bottom: 3px; }
                        @media print { body { margin: 0; } }
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
</body>
</html>