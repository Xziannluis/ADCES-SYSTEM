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

// Get filter parameters
$academic_year = $_GET['academic_year'] ?? '2023-2024';
$semester = $_GET['semester'] ?? '';
$teacher_id = $_GET['teacher_id'] ?? '';

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
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Evaluation Reports - <?php echo $_SESSION['department']; ?></h3>
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
                            <option value="2025-2026" <?php echo $academic_year == '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                            <option value="2024-2025" <?php echo $academic_year == '2024-2025' ? 'selected' : ''; ?>>2024-2025</option>
                            <option value="2023-2024" <?php echo $academic_year == '2023-2024' ? 'selected' : ''; ?>>2023-2024</option>
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
                <!-- Report Info -->
                <div class="report-info">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>CLASSROOM OBSERVATION REPORT</strong><br>
                            <strong>College/Department:</strong> <?php echo $_SESSION['department']; ?>
                        </div>
                        <div class="col-md-6 text-end">
                            <strong>Academic Year:</strong> <?php echo htmlspecialchars($academic_year); ?><br>
                            <strong>Semester:</strong> <?php echo $semester ? htmlspecialchars($semester) : 'All'; ?>
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
                                        <span class="rating-badge <?php echo $rating_class; ?>">
                                            <?php echo $rating_text; ?>
                                        </span>
                                        <div class="text-center mt-1">
                                            <small><?php echo number_format($eval['overall_avg'], 1); ?></small>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i>
                                        <h5>No Evaluation Data</h5>
                                        <p class="text-muted">No classroom observations found for the selected filters.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Report Footer -->
                <div class="report-info">
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Generated on:</strong> <?php echo date('F j, Y'); ?>
                        </div>
                        <div class="col-md-6 text-end">
                            <strong>Total Evaluations:</strong> <?php echo $stats['total_evaluations']; ?>
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
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .classroom-report { border: 1px solid #ddd; }
                        .report-header { 
                            background: #2c3e50; 
                            color: white; 
                            padding: 20px; 
                            text-align: center; 
                        }
                        .report-title { font-size: 1.5rem; font-weight: bold; }
                        .report-info { background: #f8f9fa; padding: 15px; border-bottom: 1px solid #ddd; }
                        .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                        .report-table th { background: #34495e; color: white; padding: 10px; text-align: left; }
                        .report-table td { padding: 8px; border: 1px solid #ddd; vertical-align: top; }
                        .rating-badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; }
                        .rating-excellent { background: #28a745; color: white; }
                        .rating-very-satisfactory { background: #17a2b8; color: white; }
                        .rating-satisfactory { background: #ffc107; color: black; }
                        .rating-below-satisfactory { background: #fd7e14; color: white; }
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