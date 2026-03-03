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

// Get chairperson program subjects
$program_subjects = [];
$program_stmt = $db->prepare("SELECT subject FROM evaluator_subjects WHERE evaluator_id = :evaluator_id");
$program_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
$program_stmt->execute();
$program_subjects = $program_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

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
$teachers_count_query = "SELECT COUNT(*) as teacher_count FROM teacher_assignments WHERE evaluator_id = :evaluator_id";
if (!empty($program_subjects)) {
    $placeholders = [];
    foreach ($program_subjects as $i => $subject) {
        $placeholders[] = ":subject{$i}";
    }
    $teachers_count_query .= " AND subject IN (" . implode(',', $placeholders) . ")";
}
$teachers_count_stmt = $db->prepare($teachers_count_query);
$teachers_count_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
if (!empty($program_subjects)) {
    foreach ($program_subjects as $i => $subject) {
        $teachers_count_stmt->bindValue(":subject{$i}", $subject);
    }
}
$teachers_count_stmt->execute();
$assigned_teachers_count = (int)($teachers_count_stmt->fetch(PDO::FETCH_ASSOC)['teacher_count'] ?? 0);

// Get assigned teachers list (filtered to program subjects if available)
$assigned_teachers = [];
$assigned_list_query = "SELECT ta.subject, ta.grade_level, t.name, t.department
    FROM teacher_assignments ta
    JOIN teachers t ON ta.teacher_id = t.id
    WHERE ta.evaluator_id = :evaluator_id";
if (!empty($program_subjects)) {
    $placeholders = [];
    foreach ($program_subjects as $i => $subject) {
        $placeholders[] = ":list_subject{$i}";
    }
    $assigned_list_query .= " AND ta.subject IN (" . implode(',', $placeholders) . ")";
}
$assigned_list_query .= " ORDER BY t.name";
$assigned_list_stmt = $db->prepare($assigned_list_query);
$assigned_list_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
if (!empty($program_subjects)) {
    foreach ($program_subjects as $i => $subject) {
        $assigned_list_stmt->bindValue(":list_subject{$i}", $subject);
    }
}
$assigned_list_stmt->execute();
$assigned_teachers = $assigned_list_stmt->fetchAll(PDO::FETCH_ASSOC);

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

            <div class="dashboard-stats">
                <div class="dashboard-stat stat-1">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <div class="number"><?php echo $assigned_teachers_count; ?></div>
                    <div>Assigned Teachers (Program)</div>
                </div>
                <div class="dashboard-stat stat-2">
                    <i class="fas fa-clipboard-check"></i>
                    <div class="number"><?php echo $stats['completed_evaluations']; ?></div>
                    <div>Completed Evaluations</div>
                </div>
            </div>

            

            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>My Assigned Teachers (Program)</h5>
                        </div>
                        <div class="card-body">
                            <?php if(!empty($assigned_teachers)): ?>
                                <ul class="list-group">
                                    <?php foreach($assigned_teachers as $assignment): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($assignment['name']); ?></strong>
                                                <div class="text-muted small">
                                                    <?php echo htmlspecialchars($assignment['department']); ?>
                                                    <?php if(!empty($assignment['subject'])): ?>
                                                        • <?php echo htmlspecialchars($assignment['subject']); ?>
                                                    <?php endif; ?>
                                                    <?php if(!empty($assignment['grade_level'])): ?>
                                                        • Grade <?php echo htmlspecialchars($assignment['grade_level']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <a href="evaluation.php" class="btn btn-sm btn-outline-primary">Evaluate</a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted mb-0">No assigned teachers found for your program yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
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
                                    <a href="evaluation.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Start Evaluation</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php include '../includes/footer.php'; ?>
</body>
</html>
