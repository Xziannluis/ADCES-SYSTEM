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
    // Leaders use this dashboard now (consolidated)
} elseif($_SESSION['role'] == 'edp') {
    header("Location: ../edp/dashboard.php");
    exit();
} elseif(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
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

$is_leader = in_array($_SESSION['role'], ['president', 'vice_president']);

// Get stats - leaders see system-wide, others see department
if ($is_leader) {
    $total_teachers = $teacher->getTotalTeachers();
} else {
    $department_teachers = $teacher->getByDepartment($_SESSION['department']);
}
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

// Get schedule notifications for all evaluator roles
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
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .recent-evaluation-main {
            min-width: 0;
            flex: 1 1 0;
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

        .coordinator-main {
            min-width: 0;
            flex: 1 1 auto;
            width: 100%;
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
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics (top row) -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="dashboard-stat stat-1 stat-card">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div class="number"><?php echo $is_leader ? $total_teachers : $department_teachers->rowCount(); ?></div>
                        <div><?php echo $is_leader ? 'Total Teachers' : 'Department Teachers'; ?></div>
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
                <?php endif; ?>
                    <div class="<?php echo in_array($_SESSION['role'], ['dean', 'principal']) ? 'col-md-6' : 'col-md-12'; ?> dashboard-secondary-col">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Recent Evaluations</h5>
                            </div>
                            <div class="card-body">
                                <?php if($recent_evals->rowCount() > 0): ?>
                                    <div class="list-group recent-evaluations-list">
                                        <?php while($eval = $recent_evals->fetch(PDO::FETCH_ASSOC)): 
                                            $teacher_data = $teacher->getById($eval['teacher_id']);
                                            $rating = (float)($eval['overall_avg'] ?? 0);
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