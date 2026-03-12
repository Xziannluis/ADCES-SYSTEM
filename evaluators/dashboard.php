<?php
require_once '../auth/session-check.php';

// Redirect based on role
// If a coordinator somehow reaches the general evaluators dashboard, send them to their role-specific page
if (in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    $r = $_SESSION['role'];
    if ($r === 'chairperson') header('Location: chairperson.php');
    if ($r === 'subject_coordinator') {
        if (($_SESSION['department'] ?? '') === 'ELEM') {
            header('Location: chairperson.php');
        }
        header('Location: subject_coordinator.php');
    }
    if ($r === 'grade_level_coordinator') header('Location: grade_level_coordinator.php');
    exit();
}
if(in_array($_SESSION['role'], ['president', 'vice_president'])) {
    header("Location: ../leaders/dashboard.php");
    exit();
} elseif($_SESSION['role'] == 'edp') {
    header("Location: ../edp/dashboard.php");
    exit();
} elseif(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
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

// Get department teachers
$department_teachers = $teacher->getByDepartment($_SESSION['department']);
$stats = $evaluation->getAdminStats($_SESSION['user_id']);
$recent_evals = $evaluation->getRecentEvaluations($_SESSION['user_id'], 5);

// Get assigned coordinators (for deans/principals)
$assigned_coordinators = [];
if(in_array($_SESSION['role'], ['dean', 'principal'])) {
    $coordinators_query = "
        SELECT u.id, u.name, u.role, u.department 
        FROM evaluator_assignments ea 
        JOIN users u ON ea.evaluator_id = u.id 
        WHERE ea.supervisor_id = :supervisor_id 
        AND u.status = 'active'
        ORDER BY u.role, u.name
    ";
    $coordinators_stmt = $db->prepare($coordinators_query);
    $coordinators_stmt->bindParam(':supervisor_id', $_SESSION['user_id']);
    $coordinators_stmt->execute();
    $assigned_coordinators = $coordinators_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// supervisor notifications for coordinators removed (notifications are shown only on coordinator dashboards)

// Get supervisor info (for coordinators)
$supervisor_info = [];
if(in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    $supervisor_query = "
        SELECT u.name, u.role, u.department 
        FROM evaluator_assignments ea 
        JOIN users u ON ea.supervisor_id = u.id 
        WHERE ea.evaluator_id = :evaluator_id
    ";
    $supervisor_stmt = $db->prepare($supervisor_query);
    $supervisor_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $supervisor_stmt->execute();
    $supervisor_info = $supervisor_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get schedule notifications for coordinators (SCHEDULE_ASSIGNED)
$notifications = [];
if (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    $notif_q = "SELECT * FROM audit_logs WHERE user_id = :user_id AND action = 'SCHEDULE_ASSIGNED' ORDER BY created_at DESC LIMIT 5";
    $notif_stmt = $db->prepare($notif_q);
    $notif_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get assigned teachers count
$assigned_teachers_count = 0;
if(in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    $teachers_count_query = "
        SELECT COUNT(*) as teacher_count 
        FROM teacher_assignments 
        WHERE evaluator_id = :evaluator_id
    ";
    $teachers_count_stmt = $db->prepare($teachers_count_query);
    $teachers_count_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $teachers_count_stmt->execute();
    $assigned_teachers_count = $teachers_count_stmt->fetch(PDO::FETCH_ASSOC)['teacher_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .hierarchy-card {
            border-left: 4px solid #007bff;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        .coordinator-card {
            border-left: 4px solid #28a745;
            background: linear-gradient(135deg, #f8fff9 0%, #e8f5e8 100%);
        }
        .supervisor-card {
            border-left: 4px solid #6f42c1;
            background: linear-gradient(135deg, #f8f9ff 0%, #e8ebf5 100%);
        }
        .stat-card {
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }

        .list-group-item {
            overflow-wrap: anywhere;
        }

        .recent-evaluations-list .list-group-item,
        .coordinators-list .list-group-item {
            overflow-wrap: normal;
            word-break: normal;
        }

        .recent-evaluation-row,
        .coordinator-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            width: 100%;
        }

        .recent-evaluation-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
        }

        .recent-evaluation-main,
        .coordinator-main {
            min-width: 0;
            flex: 1 1 auto;
            width: 100%;
        }

        .recent-evaluation-main h6,
        .coordinator-main strong,
        .recent-evaluation-main small,
        .coordinator-main .text-muted {
            overflow-wrap: normal;
            word-break: normal;
            white-space: normal;
        }

        .recent-evaluation-main h6 {
            display: block;
            max-width: 100%;
            line-height: 1.35;
        }

        .recent-evaluation-main small {
            display: block;
            margin-top: 0.25rem;
        }

        .recent-evaluation-actions,
        .coordinator-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .recent-evaluations-list .list-group-item {
            display: block;
            width: 100%;
        }

        .recent-evaluations-list .badge {
            flex: 0 0 auto;
        }

        .recent-evaluations-list .btn {
            flex: 0 0 auto;
        }

        @media (max-width: 991.98px) {
            .dashboard-secondary-col {
                width: 100%;
                flex: 0 0 100%;
                max-width: 100%;
            }

            .card-header .d-flex {
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .coordinators-list .coordinator-row {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.75rem;
            }

            .coordinators-list .coordinator-actions,
            .recent-evaluations-list .recent-evaluation-actions {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .recent-evaluations-list .recent-evaluation-row {
                display: flex;
                align-items: flex-start;
            }
        }

        @media (max-width: 767.98px) {
            .card-header .btn {
                width: 100%;
            }

            .recent-evaluations-list .recent-evaluation-row,
            .coordinators-list .coordinator-row {
                flex-direction: column;
                align-items: stretch;
            }

            .recent-evaluation-main,
            .coordinator-main {
                width: 100%;
            }

            .recent-evaluations-list .recent-evaluation-actions,
            .coordinators-list .coordinator-actions {
                justify-content: flex-start;
            }

            .recent-evaluations-list .recent-evaluation-actions .btn,
            .coordinators-list .coordinator-actions .btn {
                width: auto;
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
            <div class="ms-auto">
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
            
            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics (top row) -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="dashboard-stat stat-1 stat-card">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div class="number"><?php echo $department_teachers->rowCount(); ?></div>
                        <div>Department Teachers</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-stat stat-2 stat-card">
                        <i class="fas fa-clipboard-check"></i>
                        <div class="number"><?php echo $stats['completed_evaluations']; ?></div>
                        <div>Completed Evaluations</div>
                    </div>
                </div>
            </div>

            <!-- Main Row: My Coordinators (left) and Recent Evaluations (right) -->
            <div class="row mb-4">
                <?php if(in_array($_SESSION['role'], ['dean', 'principal'])): ?>
                    <div class="col-md-6 dashboard-secondary-col">
                        <div class="card mb-3">
                            <div class="card-header bg-info text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-users-cog me-2"></i>My Coordinators</h5>
                                    <a href="assign_coordinators.php" class="btn btn-sm btn-light">
                                        <i class="fas fa-user-plus me-1"></i>Manage
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(!empty($assigned_coordinators)): ?>
                                    <ul class="list-group coordinators-list">
                                        <?php foreach($assigned_coordinators as $coord): ?>
                                            <li class="list-group-item">
                                                <div class="coordinator-row">
                                                <div class="coordinator-main">
                                                    <strong><?php echo htmlspecialchars($coord['name']); ?></strong>
                                                    <span class="text-muted ms-2"><?php echo ucfirst(str_replace('_',' ',$coord['role'])); ?></span>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($coord['department']); ?></div>
                                                </div>
                                                <div class="btn-group coordinator-actions">
                                                    <a href="assign_teachers.php?evaluator_id=<?php echo $coord['id']; ?>" class="btn btn-sm btn-outline-info">View Teachers</a>
                                                </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted mb-0">You have no coordinators assigned.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 dashboard-secondary-col">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Recent Evaluations</h5>
                            </div>
                            <div class="card-body">
                                <?php if($recent_evals->rowCount() > 0): ?>
                                    <div class="list-group recent-evaluations-list">
                                        <?php while($eval = $recent_evals->fetch(PDO::FETCH_ASSOC)): 
                                            $teacher_data = $teacher->getById($eval['teacher_id']);
                                        ?>
                                        <div class="list-group-item">
                                            <div class="recent-evaluation-row">
                                                <div class="recent-evaluation-main">
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($teacher_data['name']); ?></h6>
                                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($eval['observation_date'])); ?></small>
                                                </div>
                                                <div class="recent-evaluation-actions">
                                                    <span class="badge bg-<?php 
                                                        $r = (int) floor($rating);
                                                        if($r === 5) echo 'success';
                                                        elseif($r === 4) echo 'primary';
                                                        elseif($r === 3) echo 'info';
                                                        elseif($r === 2) echo 'warning';
                                                        else echo 'danger';
                                                    ?>"><?php echo number_format($rating, 1); ?></span>
                                                    <a href="view_evaluation.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                        <h5>No Evaluations Yet</h5>
                                        <p class="text-muted">Start by conducting your first classroom evaluation.</p>
                                        <a href="evaluation.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Start Evaluation
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>