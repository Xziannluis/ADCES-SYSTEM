<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['president', 'vice_president'])) {
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

$role = $_SESSION['role'] ?? '';

// Get statistics
$total_teachers = $teacher->getTotalTeachers();
$total_evaluators = $user->getTotalEvaluators();
$stats = $evaluation->getAdminStats($_SESSION['user_id']);
$recent_evals = $evaluation->getRecentEvaluations($_SESSION['user_id'], 5);

$deans = $user->getUsersByRole('dean')->rowCount();
$principals = $user->getUsersByRole('principal')->rowCount();
$vice_presidents = $user->getUsersByRole('vice_president')->rowCount();
$chairpersons = $user->getUsersByRole('chairperson')->rowCount();
$coordinators = $user->getUsersByRole('subject_coordinator')->rowCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .stat-card { transition: transform 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); }
        .list-group-item { overflow-wrap: anywhere; }
        .recent-evaluations-list .list-group-item { overflow-wrap: normal; word-break: normal; display: block; width: 100%; }
        .recent-evaluation-row { display: flex; align-items: center; gap: 1rem; width: 100%; }
        .recent-evaluation-main { min-width: 0; flex: 1 1 0; }
        .recent-evaluation-main h6 { display: block; max-width: 100%; line-height: 1.35; }
        .recent-evaluation-main small { display: block; margin-top: 0.25rem; }
        .recent-evaluation-actions { display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; flex: 0 0 auto; white-space: nowrap; }
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="leaderMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_',' ',$_SESSION['role'])); ?>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="leaderMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
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

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="dashboard-stat stat-1 stat-card">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div class="number"><?php echo $total_teachers; ?></div>
                        <div>Total Teachers</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-stat stat-2 stat-card">
                        <i class="fas fa-user-tie"></i>
                        <div class="number"><?php echo $total_evaluators; ?></div>
                        <div>Total Evaluators</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-stat stat-3 stat-card">
                        <i class="fas fa-clipboard-check"></i>
                        <div class="number"><?php echo $stats['completed_evaluations']; ?></div>
                        <div>My Evaluations</div>
                    </div>
                </div>
            </div>

            <!-- Main content row -->
            <div class="row mb-4">
                <!-- Evaluators Summary -->
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">Evaluators Summary</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Vice Presidents
                                    <span class="badge bg-primary rounded-pill"><?php echo $vice_presidents; ?></span>
                                </li>
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
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Recent Evaluations -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Recent Evaluations</h5>
                        </div>
                        <div class="card-body">
                            <?php if($recent_evals->rowCount() > 0): ?>
                                <div class="list-group recent-evaluations-list">
                                    <?php while($eval = $recent_evals->fetch(PDO::FETCH_ASSOC)):
                                        $teacher_data = $teacher->getById($eval['teacher_id']);
                                        $rating = $eval['overall_rating'] ?? 0;
                                    ?>
                                    <div class="list-group-item">
                                        <div class="recent-evaluation-row">
                                            <div class="recent-evaluation-main">
                                                <h6 class="mb-0 text-truncate"><?php echo htmlspecialchars($teacher_data['name']); ?></h6>
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
            </div>

            <!-- Quick Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex gap-2 flex-wrap">
                                <a href="teachers.php" class="btn btn-primary">
                                    <i class="fas fa-users me-2"></i>View Teachers
                                </a>
                                <a href="evaluation.php" class="btn btn-outline-primary">
                                    <i class="fas fa-clipboard-check me-2"></i>New Evaluation
                                </a>
                                <a href="reports.php" class="btn btn-outline-primary">
                                    <i class="fas fa-chart-bar me-2"></i>Reports
                                </a>
                                <a href="observation_plan.php" class="btn btn-outline-primary">
                                    <i class="fas fa-clipboard-list me-2"></i>Observation Plan
                                </a>
                            </div>
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