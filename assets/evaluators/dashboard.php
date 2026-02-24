<?php
require_once '../auth/session-check.php';

// Redirect based on role
// If a coordinator somehow reaches the general evaluators dashboard, send them to their role-specific page
if (in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    $r = $_SESSION['role'];
    if ($r === 'chairperson') header('Location: chairperson.php');
    if ($r === 'subject_coordinator') header('Location: subject_coordinator.php');
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
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Dashboard - <?php echo $_SESSION['department']; ?></h3>
                <span>Welcome, <?php echo $_SESSION['name']; ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)</span>
            </div>
            
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
                    <div class="col-md-6">
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
                                    <ul class="list-group">
                                        <?php foreach($assigned_coordinators as $coord): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($coord['name']); ?></strong>
                                                    <span class="text-muted ms-2"><?php echo ucfirst(str_replace('_',' ',$coord['role'])); ?></span>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($coord['department']); ?></div>
                                                </div>
                                                <div class="btn-group">
                                                    <a href="assign_teachers.php?evaluator_id=<?php echo $coord['id']; ?>" class="btn btn-sm btn-outline-info">View Teachers</a>
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
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Recent Evaluations</h5>
                            </div>
                            <div class="card-body">
                                <?php if($recent_evals->rowCount() > 0): ?>
                                    <div class="list-group">
                                        <?php while($eval = $recent_evals->fetch(PDO::FETCH_ASSOC)): 
                                            $teacher_data = $teacher->getById($eval['teacher_id']);
                                        ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($teacher_data['name']); ?></h6>
                                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($eval['observation_date'])); ?></small>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge bg-<?php 
                                                        $rating = $eval['overall_avg'];
                                                        if($rating >= 4.6) echo 'success';
                                                        elseif($rating >= 3.6) echo 'primary';
                                                        elseif($rating >= 2.9) echo 'info';
                                                        elseif($rating >= 1.8) echo 'warning';
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
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>