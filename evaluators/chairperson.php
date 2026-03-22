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

function tableExists($db, $table) {
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE :table");
        $stmt->bindValue(':table', $table);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('tableExists failed for ' . $table . ': ' . $e->getMessage());
        return false;
    }
}

// Get chairperson program subjects
$program_subjects = [];
$hasEvaluatorSubjects = tableExists($db, 'evaluator_subjects');
$hasEvaluatorAssignments = tableExists($db, 'evaluator_assignments');
$hasTeacherAssignments = tableExists($db, 'teacher_assignments');

if ($hasEvaluatorSubjects) {
    $program_stmt = $db->prepare("SELECT subject FROM evaluator_subjects WHERE evaluator_id = :evaluator_id");
    $program_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $program_stmt->execute();
    $program_subjects = $program_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get supervisor info (who supervises this chairperson)
$supervisor_info = [];
if ($hasEvaluatorAssignments) {
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

// Get assigned teachers count
$assigned_teachers_count = 0;
if ($hasTeacherAssignments) {
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
}

// Get assigned teachers list (filtered to program subjects if available)
$assigned_teachers = [];
if ($hasTeacherAssignments) {
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
}

// Get stats and recent evaluations for this evaluator
$stats = $evaluation->getAdminStats($_SESSION['user_id']);
$recent_evals = $evaluation->getRecentEvaluations($_SESSION['user_id'], 5);

// Load schedule notifications for this coordinator
$notifications = [];
$unread_count = 0;
try {
    $notif_q = "SELECT * FROM notifications WHERE user_id = :user_id AND type = 'schedule' AND is_read = 0 ORDER BY created_at DESC LIMIT 10";
    $notif_stmt = $db->prepare($notif_q);
    $notif_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notifications as $n) { if (!$n['is_read']) $unread_count++; }
} catch (PDOException $e) {
    $notifications = [];
}

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
    <div class="main-content" style="padding:0;">
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn position-relative" type="button" id="notifBell" data-bs-toggle="dropdown" aria-expanded="false" style="color:#fff;font-size:1.3rem;">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="font-size:0;">
                            <span class="visually-hidden">New notifications</span>
                        </span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="notifBell" style="width:420px;max-height:480px;overflow-y:auto;padding:0;border-radius:10px;">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom" style="background:#2c3e50;color:#fff;border-radius:10px 10px 0 0;">
                            <strong><i class="fas fa-bell me-2"></i>Notifications</strong>
                            <?php if ($unread_count > 0): ?>
                            <button class="btn btn-sm text-white-50 p-0" onclick="event.stopPropagation();markAllRead()" style="font-size:0.85rem;">Mark all as read</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($notifications)): ?>
                            <div id="notificationList">
                            <?php foreach ($notifications as $notif): ?>
                            <div class="notif-item border-bottom px-3 py-3" id="notif-<?php echo (int)$notif['id']; ?>" style="white-space:normal;transition:all 0.3s ease;">
                                <div class="d-flex align-items-start gap-2">
                                    <div style="flex:1;min-width:0;">
                                        <div class="fw-semibold" style="font-size:0.95rem;">
                                            <?php if (!$notif['is_read']): ?><i class="fas fa-circle text-danger me-1" style="font-size:0.5rem;vertical-align:middle;"></i><?php endif; ?>
                                            <?php echo htmlspecialchars($notif['title']); ?>
                                        </div>
                                        <div class="text-muted mt-1" style="font-size:0.85rem;line-height:1.4;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="text-muted mt-1" style="font-size:0.78rem;"><i class="far fa-clock me-1"></i><?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></div>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1 flex-shrink-0" onclick="event.stopPropagation();markRead(<?php echo (int)$notif['id']; ?>)" title="Mark as read" style="font-size:0.78rem;">
                                        <i class="fas fa-check me-1"></i>Read
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted py-4" style="font-size:0.95rem;"><i class="far fa-bell-slash me-2"></i>No notifications</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="chairpersonMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (Chairperson)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="chairpersonMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

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
                                                        $rating = (float)($eval['overall_avg'] ?? 0);
                                                        $r = (int) floor($rating);
                                                        if($r === 5) echo 'success';
                                                        elseif($r === 4) echo 'primary';
                                                        elseif($r === 3) echo 'info';
                                                        elseif($r === 2) echo 'warning';
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
    </div>
    <script>
    function markRead(id) {
        fetch('../includes/notification_mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
        }).then(r => r.json()).then(d => {
            if (d.success) {
                var el = document.getElementById('notif-' + id);
                if (el) { el.style.opacity = '0'; el.style.maxHeight = '0'; el.style.padding = '0'; el.style.overflow = 'hidden'; setTimeout(function(){ el.remove(); checkEmpty(); }, 300); }
                updateBellDot();
            }
        });
    }
    function markAllRead() {
        fetch('../includes/notification_mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'mark_all=1'
        }).then(r => r.json()).then(d => {
            if (d.success) {
                document.querySelectorAll('#notificationList .notif-item').forEach(function(el){ el.style.opacity = '0'; el.style.maxHeight = '0'; el.style.padding = '0'; el.style.overflow = 'hidden'; setTimeout(function(){ el.remove(); checkEmpty(); }, 300); });
                updateBellDot();
            }
        });
    }
    function updateBellDot() {
        var remaining = document.querySelectorAll('#notificationList .notif-item');
        var badge = document.querySelector('#notifBell .bg-danger');
        if (remaining.length === 0 && badge) badge.remove();
    }
    function checkEmpty() {
        var list = document.getElementById('notificationList');
        if (list && list.querySelectorAll('.notif-item').length === 0) {
            list.innerHTML = '<div class="text-center text-muted py-4" style="font-size:0.95rem;"><i class="far fa-bell-slash me-2"></i>No notifications</div>';
        }
    }
    </script>

    <?php include '../includes/footer.php'; ?>
    <?php include '../includes/email_verify_prompt.php'; ?>
</body>
</html>
