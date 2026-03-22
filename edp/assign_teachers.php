<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/User.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$teacher = new Teacher($db);

if (!isset($_GET['evaluator_id']) || empty($_GET['evaluator_id'])) {
    $_SESSION['error'] = "Evaluator ID is required.";
    header('Location: users.php');
    exit();
}

$evaluator_id = $_GET['evaluator_id'];
$evaluator = $user->getById($evaluator_id);

if (!$evaluator) {
    $_SESSION['error'] = "Invalid evaluator.";
    header('Location: users.php');
    exit();
}

// EDP no longer assigns teachers; deans manage assignments.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_teacher') {
    $_SESSION['error'] = "Teacher assignments are managed by deans.";
    header("Location: assign_teachers.php?evaluator_id=" . $evaluator_id);
    exit();
}

// Handle teacher removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assignment') {
    $assignment_id = $_POST['assignment_id'];
    
    $delete_query = "DELETE FROM teacher_assignments WHERE id = :assignment_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':assignment_id', $assignment_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Teacher assignment removed successfully!";
    } else {
        $_SESSION['error'] = "Failed to remove teacher assignment.";
    }
    
    header("Location: assign_teachers.php?evaluator_id=" . $evaluator_id);
    exit();
}

// Get assigned teachers
$assigned_query = "SELECT ta.*, t.name as teacher_name, t.department 
                  FROM teacher_assignments ta 
                  JOIN teachers t ON ta.teacher_id = t.id 
                  WHERE ta.evaluator_id = :evaluator_id 
                  ORDER BY ta.subject, ta.grade_level, t.name";
$assigned_stmt = $db->prepare($assigned_query);
$assigned_stmt->bindParam(':evaluator_id', $evaluator_id);
$assigned_stmt->execute();
$assigned_teachers = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Teacher assignment creation is disabled for EDP users.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Teachers - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .assignment-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }
        .assignment-header {
            background-color: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .assignment-body {
            padding: 15px;
        }
        .teacher-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .teacher-item {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .teacher-item:last-child {
            border-bottom: none;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .form-container {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        @media (max-width: 991.98px) {
            .d-flex.justify-content-between.align-items-center.mb-4 {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.75rem;
            }
        }

        @media (max-width: 767.98px) {
            .teacher-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.35rem;
            }

            .assignment-header,
            .assignment-body,
            .form-container {
                padding: 1rem;
            }

            .card-body .row > div {
                margin-bottom: 0.75rem;
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
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Evaluator Information -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Evaluator Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($evaluator['name']); ?></p>
                            <p><strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $evaluator['role'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Department:</strong> <?php echo htmlspecialchars($evaluator['department']); ?></p>
                            <p><strong>Assigned Teachers:</strong> <?php echo count($assigned_teachers); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Teacher assignments are managed by the Dean. This page is read-only for EDP users.
            </div>

            <!-- Current Assignments -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Current Teacher Assignments</h5>
                </div>
                <div class="card-body">
                    <?php if(empty($assigned_teachers)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users fa-3x mb-3"></i>
                            <h5>No Teachers Assigned</h5>
                            <p>Teacher assignments are managed by the Dean.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        // Group assignments by subject/grade level
                        $grouped_assignments = [];
                        foreach ($assigned_teachers as $assignment) {
                            $key = $assignment['subject'] ?: 'Grade ' . $assignment['grade_level'];
                            $grouped_assignments[$key][] = $assignment;
                        }
                        ?>
                        
                        <?php foreach($grouped_assignments as $category => $assignments): ?>
                        <div class="assignment-card">
                            <div class="assignment-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($category); ?>
                                    <span class="badge bg-secondary ms-2"><?php echo count($assignments); ?> teachers</span>
                                </h6>
                            </div>
                            <div class="assignment-body">
                                <ul class="teacher-list">
                                    <?php foreach($assignments as $assignment): ?>
                                    <li class="teacher-item">
                                        <div>
                                            <strong><?php echo htmlspecialchars($assignment['teacher_name']); ?></strong>
                                            <small class="text-muted ms-2"><?php echo htmlspecialchars($assignment['department']); ?></small>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>