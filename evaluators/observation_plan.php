<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

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

$raw_department = (string)($_SESSION['department'] ?? '');
$department_display = $department_map[$raw_department] ?? $raw_department;

// Get semester filter
$semester = $_GET['semester'] ?? '1st';
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';

// Auto-detect academic year if not set
if (empty($academic_year)) {
    $month = (int)date('n');
    $year = (int)date('Y');
    if ($month >= 6) {
        $academic_year = $year . '-' . ($year + 1);
    } else {
        $academic_year = ($year - 1) . '-' . $year;
    }
}

// Get teachers who have evaluations in this academic year and semester
$query = "SELECT DISTINCT t.id, t.name, t.evaluation_schedule, t.evaluation_room,
                 e.observation_date, e.status as eval_status, e.faculty_signature
          FROM teachers t
          JOIN evaluations e ON e.teacher_id = t.id
          WHERE t.department = :department 
          AND e.academic_year = :academic_year
          AND e.semester = :semester
          ORDER BY t.name ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $raw_department);
$stmt->bindParam(':academic_year', $academic_year);
$stmt->bindParam(':semester', $semester);
$stmt->execute();
$teachers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build eval_data and observer_map from results
$eval_data = [];
$observer_map = [];
$dean_name = $_SESSION['name'] ?? '';

foreach ($teachers_list as $t) {
    // Store evaluation data
    $obs_date = $t['observation_date'] ?? '';
    $is_done = ($t['eval_status'] === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';
    $eval_data[$t['id']] = ['date' => $obs_date, 'done' => $is_done, 'faculty_signature' => $faculty_sig];

    // Get observers from evaluations
    $obs_query = "SELECT DISTINCT u.name 
                  FROM evaluations e 
                  JOIN users u ON e.evaluator_id = u.id 
                  WHERE e.teacher_id = :teacher_id 
                  AND e.academic_year = :academic_year
                  AND e.semester = :semester
                  ORDER BY u.name";
    $obs_stmt = $db->prepare($obs_query);
    $obs_stmt->bindParam(':teacher_id', $t['id']);
    $obs_stmt->bindParam(':academic_year', $academic_year);
    $obs_stmt->bindParam(':semester', $semester);
    $obs_stmt->execute();
    $observers = $obs_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Also get assigned evaluators from teacher_assignments
    $assign_query = "SELECT DISTINCT u.name 
                     FROM teacher_assignments ta 
                     JOIN users u ON ta.evaluator_id = u.id 
                     WHERE ta.teacher_id = :teacher_id
                     ORDER BY u.name";
    $assign_stmt = $db->prepare($assign_query);
    $assign_stmt->bindParam(':teacher_id', $t['id']);
    $assign_stmt->execute();
    $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Merge and deduplicate
    $all_observers = array_unique(array_merge($observers, $assigned));

    // Always include the dean
    if (!empty($dean_name) && !in_array($dean_name, $all_observers)) {
        array_unshift($all_observers, $dean_name);
    }

    $observer_map[$t['id']] = $all_observers;
}

// Filter by month if selected
if (!empty($filter_month)) {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data, $filter_month) {
        $date = $eval_data[$t['id']]['date'] ?? '';
        if (empty($date)) return false;
        return date('n', strtotime($date)) == $filter_month;
    });
    $teachers_list = array_values($teachers_list);
}
$dean_role_display = ucfirst(str_replace('_', ' ', $_SESSION['role']));

// Get dean's signature from most recent evaluation
$dean_signature = '';
try {
    $sig_query = "SELECT rater_signature FROM evaluations WHERE evaluator_id = :evaluator_id AND rater_signature IS NOT NULL AND rater_signature != '' ORDER BY created_at DESC LIMIT 1";
    $sig_stmt = $db->prepare($sig_query);
    $sig_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $sig_stmt->execute();
    $sig_row = $sig_stmt->fetch(PDO::FETCH_ASSOC);
    if ($sig_row) {
        $dean_signature = $sig_row['rater_signature'];
    }
} catch (Exception $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classroom Observation Plan - <?php echo htmlspecialchars($raw_department); ?></title>
    <?php include '../includes/header.php'; ?>
    <style>
        .plan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .plan-table th, .plan-table td {
            border: 1px solid #333;
            padding: 8px 10px;
            vertical-align: middle;
        }
        .plan-table th {
            background: #2c3e50;
            color: white;
            font-weight: 600;
            text-align: center;
        }
        .plan-table td {
            font-size: 0.9rem;
        }
        .plan-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .plan-header {
            text-align: center;
            margin-bottom: 15px;
        }
        .plan-header h4 {
            font-weight: 700;
            margin-bottom: 2px;
        }
        .plan-header p {
            margin: 2px 0;
            font-size: 0.95rem;
        }
        .prepared-by {
            margin-top: 40px;
            font-size: 0.95rem;
        }
        .prepared-by p:first-child {
            margin-bottom: 0;
        }
        .prepared-by .sig-img {
            display: block;
            max-height: 50px;
            max-width: 200px;
            margin-top: 5px;
            margin-bottom: -10px;
        }
        .prepared-by .name-line {
            font-weight: 700;
            text-decoration: underline;
            margin: 0;
        }
        .prepared-by .role-dept {
            margin: 2px 0 0 0;
            font-size: 0.9rem;
        }
        .print-only { display: none; }
        .no-print {}

        @media print {
            @page {
                size: portrait;
                margin: 12mm;
            }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .sidebar, .sidebar-backdrop, .mobile-sidebar-toggle,
            .mobile-sidebar-header, .dashboard-topbar, .dashboard-bg-layer {
                display: none !important;
            }
            .main-content, .container-fluid {
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
            body { background: #fff !important; }
            .plan-table th {
                background: #fff !important;
                color: #000 !important;
                border: 1.5px solid #000 !important;
                print-color-adjust: exact;
            }
            .plan-table td {
                border: 1.5px solid #000 !important;
            }
            .plan-table tr:nth-child(even) {
                background: #fff !important;
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
                <div class="no-print">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
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

            <!-- Filters (screen only) -->
            <div class="card mb-3 no-print">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Academic Year</label>
                            <input type="text" name="academic_year" class="form-control" value="<?php echo htmlspecialchars($academic_year); ?>" placeholder="e.g. 2025-2026">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Semester</label>
                            <select name="semester" class="form-select">
                                <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Month</label>
                            <select name="month" class="form-select">
                                <option value="">All Months</option>
                                <?php
                                $months = ['1'=>'January','2'=>'February','3'=>'March','4'=>'April','5'=>'May','6'=>'June','7'=>'July','8'=>'August','9'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                                foreach ($months as $num => $name):
                                ?>
                                <option value="<?php echo $num; ?>" <?php echo $filter_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Printable Observation Plan -->
            <div style="background: white; padding: 30px; border: 1px solid #ddd; box-shadow: 0 0 10px rgba(0,0,0,0.05);">
                
                <!-- Print Header -->
                <div class="print-only" style="padding: 8px 0 10px; border-bottom: 1px solid #000; margin-bottom: 0;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap: 10px;">
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
                    <div style="text-align:center; margin-top: 8px;">
                        <strong style="font-size: 12px; text-transform: uppercase;"><?php echo htmlspecialchars($department_display); ?></strong>
                    </div>
                </div>

                <!-- Screen Header -->
                <div class="plan-header">
                    <h4 class="no-print">Classroom Observation Plan</h4>
                    <div class="print-only" style="text-align:center; margin-top: 8px; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: bold;">Classroom Observation Plan</span><br>
                        <span style="font-size: 11px;"><?php echo htmlspecialchars($semester); ?> semester SY <?php echo htmlspecialchars($academic_year); ?></span>
                    </div>
                    <p class="no-print"><strong><?php echo htmlspecialchars($department_display); ?></strong></p>
                    <p class="no-print"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></p>
                </div>

                <!-- Observation Plan Table -->
                <div class="table-responsive">
                    <table class="plan-table">
                        <thead>
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 22%;">Teacher</th>
                                <th style="width: 15%;">Date</th>
                                <th style="width: 28%;">Name of Observer</th>
                                <th style="width: 15%;">Signature of the Teacher</th>
                                <th style="width: 15%;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($teachers_list) > 0): ?>
                                <?php $counter = 1; foreach ($teachers_list as $t): ?>
                                <tr>
                                    <td class="text-center"><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($t['name']); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $date = $eval_data[$t['id']]['date'] ?? '';
                                        if (!empty($date)) {
                                            echo htmlspecialchars(date('F j, Y', strtotime($date)));
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $observers = $observer_map[$t['id']] ?? [];
                                        echo htmlspecialchars(implode(', ', $observers));
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $faculty_sig = $eval_data[$t['id']]['faculty_signature'] ?? '';
                                        if (!empty($faculty_sig)): ?>
                                            <img src="<?php echo $faculty_sig; ?>" alt="Teacher Signature" style="max-height: 40px; max-width: 120px;">
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo ($eval_data[$t['id']]['done'] ?? false) ? '<em>done</em>' : ''; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No active teachers found in this department.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Prepared By (print only) -->
                <div class="prepared-by print-only">
                    <p><em>Prepared by:</em></p>
                    <?php if (!empty($dean_signature)): ?>
                    <img class="sig-img" src="<?php echo $dean_signature; ?>" alt="Signature">
                    <?php endif; ?>
                    <p class="name-line"><?php echo htmlspecialchars(strtoupper($dean_name)); ?></p>
                    <p class="role-dept"><?php echo htmlspecialchars($dean_role_display); ?>, <?php echo htmlspecialchars($raw_department); ?></p>
                </div>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
