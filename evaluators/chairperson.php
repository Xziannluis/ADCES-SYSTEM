<?php
require_once '../auth/session-check.php';
// Only allow chairperson
if($_SESSION['role'] !== 'chairperson') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/User.php';
require_once '../models/Evaluation.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);
$user = new User($db);
$evaluation = new Evaluation($db);

// Get supervisor info (who supervises this chairperson)
$supervisor_info = [];
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

// Get assigned teachers count
$assigned_teachers_count = 0;
$teachers_count_query = "
    SELECT COUNT(*) as teacher_count 
    FROM teacher_assignments 
    WHERE evaluator_id = :evaluator_id
";
$teachers_count_stmt = $db->prepare($teachers_count_query);
$teachers_count_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
$teachers_count_stmt->execute();
$assigned_teachers_count = $teachers_count_stmt->fetch(PDO::FETCH_ASSOC)['teacher_count'];

// Get stats and recent evaluations for this evaluator
$stats = $evaluation->getAdminStats($_SESSION['user_id']);
$recent_evals = $evaluation->getRecentEvaluations($_SESSION['user_id'], 5);

// Load schedule notifications for this coordinator
$notifications = [];
$notif_q = "SELECT * FROM audit_logs WHERE user_id = :user_id AND action = 'SCHEDULE_ASSIGNED' ORDER BY created_at DESC LIMIT 5";
$notif_stmt = $db->prepare($notif_q);
$notif_stmt->bindParam(':user_id', $_SESSION['user_id']);
$notif_stmt->execute();
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chairperson Dashboard - <?php echo htmlspecialchars($_SESSION['department']); ?></title>
    <?php include '../includes/header.php'; ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Coordinator Dashboard - Chairperson</h3>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (Chairperson)</span>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card coordinator-card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-user me-2"></i>Coordinator Overview
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Supervisor Information</h6>
                                    <?php if($supervisor_info): ?>
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($supervisor_info['name']); ?></p>
                                                <p class="mb-1"><strong>Role:</strong> <?php echo ucfirst(str_replace('_',' ',$supervisor_info['role'])); ?></p>
                                                <p class="mb-0"><strong>Department:</strong> <?php echo htmlspecialchars($supervisor_info['department']); ?></p>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">Not assigned to a supervisor yet.</p>
                                        <a href="../edp/users.php" class="btn btn-sm btn-outline-secondary">Contact EDP for Assignment</a>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <h6>My Responsibilities</h6>
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <p class="mb-2"><i class="fas fa-chalkboard-teacher me-2 text-success"></i><strong>Assigned Teachers:</strong> <?php echo $assigned_teachers_count; ?></p>
                                            <p class="mb-2"><i class="fas fa-clipboard-check me-2 text-primary"></i><strong>Completed Evaluations:</strong> <?php echo $stats['completed_evaluations']; ?></p>
                                            <p class="mb-0"><i class="fas fa-robot me-2 text-info"></i><strong>AI Recommendations:</strong> <?php echo $stats['ai_recommendations']; ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if(!empty($notifications)): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="card border-info">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0">Notifications <span class="badge bg-info ms-2"><?php echo count($notifications); ?></span></h6>
                                        </div>
                                        <div class="card-body">
                                            <ul class="list-group list-group-flush">
                                                <?php foreach($notifications as $n): ?>
                                                <li class="list-group-item">
                                                    <div><?php echo htmlspecialchars($n['description']); ?></div>
                                                    <div class="small text-muted mt-1"><?php echo date('M j, Y g:ia', strtotime($n['created_at'])); ?></div>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Recent Evaluations</h5></div>
                        <div class="card-body">
                            <?php if($recent_evals->rowCount() > 0): ?>
                                <div class="list-group">
                                    <?php while($eval = $recent_evals->fetch(PDO::FETCH_ASSOC)):
                                        $teacher_data = $teacher->getById($eval['teacher_id']); ?>
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
                                                    ?>"><?php echo number_format($rating,1); ?></span>
                                                    <a href="evaluation_view.php?id=<?php echo $eval['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
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
                                    <a href="evaluation.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Start Evaluation</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Quick Actions</h5></div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="evaluation.php" class="btn btn-primary mb-2"><i class="fas fa-clipboard-check me-2"></i>New Evaluation</a>
                                <!-- 'My Assigned Teachers' removed for coordinator roles -->
                                <a href="reports.php" class="btn btn-outline-secondary"> <i class="fas fa-chart-bar me-2"></i>View Reports</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php include '../includes/footer.php'; ?>
</body>
</html>
