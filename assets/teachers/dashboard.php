<?php
session_start();

// Check if teacher is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';

$db = (new Database())->getConnection();
$teacher = new Teacher($db);
$evaluation = new Evaluation($db);

// Get teacher info
$teacher_data = $teacher->getById($_SESSION['teacher_id']);
if(!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: ../login.php");
    exit();
}

// Get evaluations for this teacher
$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role 
          FROM evaluations e
          JOIN users u ON e.evaluator_id = u.id
          WHERE e.teacher_id = :teacher_id
          ORDER BY e.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':teacher_id', $_SESSION['teacher_id']);
$stmt->execute();
$evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - AI Classroom Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --info: #3498db;
            --warning: #f39c12;
            --danger: #e74c3c;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: var(--primary) !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
        }

        .sidebar {
            background: var(--primary);
            min-height: 100vh;
            padding: 30px 0;
            color: white;
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            margin: 10px 0;
            padding: 12px 25px;
            border-left: 4px solid transparent;
            transition: all 0.3s;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--secondary);
            color: white;
        }

        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .teacher-info-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .teacher-info-card h3 {
            margin: 0;
            font-weight: 700;
        }

        .schedule-bell {
            border: none;
            background: #fff;
            color: #2c3e50;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }

        .schedule-bell .schedule-dot {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #dc3545;
            border: 1px solid #fff;
            display: none;
        }

        .schedule-bell.show-dot .schedule-dot {
            display: inline-block;
        }

        .teacher-info-card p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }

        .evaluation-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .evaluation-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .evaluation-card .card-title {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 10px;
        }

        .schedule-badge {
            display: inline-block;
            background: var(--info);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-top: 10px;
        }

        .schedule-badge i {
            margin-right: 5px;
        }

        .btn-view {
            background: var(--secondary);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-view:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
        }

        .no-evaluations {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: #6c757d;
        }

        .no-evaluations i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-completed {
            background: #d4edda;
            color: #155724;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>My Dashboard
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="teacherMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                            <li>
                                <a class="dropdown-item" href="profile.php">
                                    <i class="fas fa-user me-2"></i>Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="change-password.php">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="../auth/logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Sign Out
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Main Content -->
            <div class="col-md-10 offset-md-1">
                <!-- Alerts -->
                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show mt-3">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show mt-3">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>


                <!-- Teacher Info Card -->
                <div class="teacher-info-card mt-4">
                    <h3>
                        Welcome, <?php echo htmlspecialchars($teacher_data['name']); ?>!
                        <button type="button" class="btn btn-sm schedule-bell ms-2 position-relative" id="scheduleBell" data-bs-toggle="popover" data-bs-placement="bottom">
                            <i class="fas fa-bell"></i>
                            <span class="schedule-dot"></span>
                        </button>
                    </h3>
                    <p><i class="fas fa-building me-2"></i>Department: <?php echo htmlspecialchars($teacher_data['department']); ?></p>
                    <p><i class="fas fa-check-circle me-2"></i>Status: <span class="badge badge-success">Active</span></p>
                </div>

                <!-- Evaluation Schedule Info -->
                <div class="content-area">
                    <h4 class="mb-4">
                        <i class="fas fa-calendar-alt me-2"></i>Evaluation Schedule & Room
                    </h4>
                    
                    <?php if($teacher_data['evaluation_schedule']): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><i class="fas fa-clock me-2"></i>Scheduled Date & Time:</strong>
                                <p class="mb-0"><?php echo date('F d, Y \a\t h:i A', strtotime($teacher_data['evaluation_schedule'])); ?></p>
                            </div>
                            <div class="col-md-6">
                                <strong><i class="fas fa-door-open me-2"></i>Room Location:</strong>
                                <p class="mb-0"><?php echo htmlspecialchars($teacher_data['evaluation_room'] ?? 'Not assigned yet'); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning alert-dismissible fade show">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>No evaluation schedule assigned yet.</strong> Please contact your department evaluator.
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Evaluations List -->
                <div class="content-area">
                    <h4 class="mb-4">
                        <i class="fas fa-file-pdf me-2"></i>My Evaluations
                    </h4>

                    <?php if(count($evaluations) > 0): ?>
                        <?php foreach($evaluations as $eval): ?>
                        <div class="evaluation-card">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h6 class="card-title">
                                        Evaluation by <?php echo htmlspecialchars($eval['evaluator_name']); ?>
                                        <span class="ms-2">
                                            <?php if($eval['status'] === 'completed'): ?>
                                                <span class="badge-status badge-completed">
                                                    <i class="fas fa-check-circle me-1"></i>Completed
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-status badge-pending">
                                                    <i class="fas fa-clock me-1"></i>Pending
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </h6>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-user-tag me-2"></i>
                                        Evaluator Role: <strong><?php echo ucfirst(str_replace('_', ' ', $eval['evaluator_role'])); ?></strong>
                                    </p>
                                    <p class="text-muted mb-0">
                                        <i class="fas fa-calendar me-2"></i>
                                        Submitted: <?php echo date('F d, Y h:i A', strtotime($eval['created_at'])); ?>
                                    </p>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <?php if($eval['status'] === 'completed'): ?>
                                    <a href="view-evaluation.php?eval_id=<?php echo $eval['id']; ?>" class="btn-view">
                                        <i class="fas fa-eye me-2"></i>View Evaluation
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">
                                        <i class="fas fa-hourglass-half me-2"></i>Awaiting Completion
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-evaluations">
                            <i class="fas fa-inbox"></i>
                            <h5>No Evaluations Yet</h5>
                            <p>You don't have any evaluations yet. Once evaluators complete your evaluation, it will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const bell = document.getElementById('scheduleBell');
            if (!bell) return;

            const scheduleText = <?php echo json_encode(!empty($teacher_data['evaluation_schedule'])
                ? date('F d, Y \a\t h:i A', strtotime($teacher_data['evaluation_schedule']))
                : 'No schedule assigned'); ?>;
            const roomText = <?php echo json_encode($teacher_data['evaluation_room'] ?? 'Not assigned yet'); ?>;
            const hasSchedule = <?php echo !empty($teacher_data['evaluation_schedule']) ? 'true' : 'false'; ?>;
            const teacherId = <?php echo json_encode($teacher_data['id'] ?? ''); ?>;
            const scheduleKey = `schedule_seen_${teacherId}_${scheduleText}_${roomText}`;

            const content = hasSchedule
                ? `<div><strong>Schedule:</strong> ${scheduleText}</div><div><strong>Room:</strong> ${roomText}</div>`
                : '<div>No schedule assigned yet.</div>';

            new bootstrap.Popover(bell, {
                html: true,
                trigger: 'focus',
                content: content
            });

            if (hasSchedule && !localStorage.getItem(scheduleKey)) {
                bell.classList.add('show-dot');
            }

            bell.addEventListener('click', () => {
                if (!hasSchedule) return;
                localStorage.setItem(scheduleKey, 'seen');
                bell.classList.remove('show-dot');
            });
        })();
    </script>
</body>
</html>
