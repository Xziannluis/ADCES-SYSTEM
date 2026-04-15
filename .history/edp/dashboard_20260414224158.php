<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';
require_once '../models/User.php';

$database = new Database();
$db = $database->getConnection();

$teacher = new Teacher($db);
$evaluation = new Evaluation($db);
$user = new User($db);

// Get statistics
$total_teachers = $teacher->getTotalTeachers();
$total_evaluators = $user->getTotalEvaluators(); // Total users who can evaluate


// Get users by role for the summary
$presidents = $user->getUsersByRole('president')->rowCount();
$vice_presidents = $user->getUsersByRole('vice_president')->rowCount();
$deans = $user->getUsersByRole('dean')->rowCount();
$principals = $user->getUsersByRole('principal')->rowCount();
$chairpersons = $user->getUsersByRole('chairperson')->rowCount();
$coordinators = $user->getUsersByRole('subject_coordinator')->rowCount();
$glc_count = $user->getUsersByRole('grade_level_coordinator')->rowCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDP Dashboard - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        /* ── Dashboard body ── */
        .edp-dashboard-body {
            padding: 28px 24px 10px;
            position: relative;
            z-index: 2;
        }
        .edp-dashboard-body .page-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }
        .edp-dashboard-body .page-title-row h4 {
            font-weight: 700;
            color: #2c3e50;
            margin: 0;
        }

        /* ── Stat cards ── */
        .edp-stat-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(15,60,120,0.09);
            padding: 22px 18px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #e5edf7;
        }
        .edp-stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(15,60,120,0.15);
        }
        .edp-stat-card .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 12px;
        }
        .edp-stat-card .stat-icon.blue  { background: rgba(52,152,219,0.13); color: #2980b9; }
        .edp-stat-card .stat-icon.green { background: rgba(39,174,96,0.13);  color: #219653; }
        .edp-stat-card .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: #2c3e50;
            line-height: 1;
            margin-bottom: 4px;
        }
        .edp-stat-card .stat-label {
            font-size: 0.88rem;
            color: #6c757d;
            font-weight: 600;
        }

        /* ── Evaluators summary card ── */
        .summary-card {
            border-radius: 14px;
            border: 1px solid #e5edf7;
            box-shadow: 0 4px 18px rgba(15,60,120,0.08);
            overflow: hidden;
        }
        .summary-card .card-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: #fff; 
            padding: 14px 20px;
            border: none;
        }
        .summary-card .card-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1.05rem;
        }
        .summary-card .list-group-item {
            padding: 12px 20px;
            font-weight: 500;
            border-color: #f0f3f8;
        }
        .summary-card .list-group-item:hover {
            background: #f8fafc;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content" style="padding:0;">
        <!-- Blurred Building Background Layer -->
        <div class="dashboard-bg-layer">
            <div class="bg-img"></div>
        </div>

        <!-- Top Header Bar -->
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto">
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog fa-fw me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key fa-fw me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Dashboard Body -->
        <div class="edp-dashboard-body">
            
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="edp-stat-card">
                        <div class="stat-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="stat-number"><?php echo $total_teachers; ?></div>
                        <div class="stat-label">Total Teachers</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="edp-stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-tie"></i></div>
                        <div class="stat-number"><?php echo $total_evaluators; ?></div>
                        <div class="stat-label">Total Evaluators</div>
                    </div>
                </div>
            </div>

            <!-- Evaluators Summary -->
            <div class="row">
                <div class="col-12">
                    <div class="card summary-card">
                        <div class="card-header">
                            <h5><i class="fas fa-users me-2"></i>Evaluators Summary</h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Deans
                                    <span class="badge bg-primary rounded-pill"><?php echo $deans; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Principals
                                    <span class="badge bg-primary rounded-pill"><?php echo $principals; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Chairpersons
                                    <span class="badge bg-primary rounded-pill"><?php echo $chairpersons; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Subject Coordinators
                                    <span class="badge bg-primary rounded-pill"><?php echo $coordinators; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Grade Level Coordinators
                                    <span class="badge bg-primary rounded-pill"><?php echo $glc_count; ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <?php include '../includes/email_verify_prompt.php'; ?>
</body>
</html>