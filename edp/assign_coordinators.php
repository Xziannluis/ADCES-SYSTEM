<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/User.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

if (!isset($_GET['supervisor_id']) || empty($_GET['supervisor_id'])) {
    $_SESSION['error'] = "Supervisor ID is required.";
    header('Location: users.php');
    exit();
}

$supervisor_id = $_GET['supervisor_id'];
$supervisor = $user->getById($supervisor_id);

if (!$supervisor || !in_array($supervisor['role'], ['dean', 'principal'])) {
    $_SESSION['error'] = "Invalid supervisor.";
    header('Location: users.php');
    exit();
}

// EDP can only view coordinator assignments; deans manage assignments.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['assign_coordinator', 'remove_assignment'], true)) {
    $_SESSION['error'] = "Coordinator assignments are managed by deans.";
    header("Location: assign_coordinators.php?supervisor_id=" . $supervisor_id);
    exit();
}

// Get assigned coordinators
$assigned_query = "SELECT ea.*, u.name as coordinator_name, u.role as coordinator_role, u.department 
                  FROM evaluator_assignments ea 
                  JOIN users u ON ea.evaluator_id = u.id 
                  WHERE ea.supervisor_id = :supervisor_id 
                  ORDER BY u.role, u.name";
$assigned_stmt = $db->prepare($assigned_query);
$assigned_stmt->bindParam(':supervisor_id', $supervisor_id);
$assigned_stmt->execute();
$assigned_coordinators = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Assignment creation is disabled for EDP users.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Coordinators - AI Classroom Evaluation</title>
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
        .coordinator-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .coordinator-item {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .coordinator-item:last-child {
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
        .role-badge {
            background-color: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            margin-left: 5px;
        }

        @media (max-width: 991.98px) {
            .d-flex.justify-content-between.align-items-center.mb-4 {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.75rem;
            }
        }

        @media (max-width: 767.98px) {
            .coordinator-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.35rem;
            }

            .assignment-header,
            .assignment-body,
            .form-container {
                padding: 1rem;
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
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Supervisor Information -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Supervisor Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($supervisor['name']); ?></p>
                            <p><strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $supervisor['role'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Department:</strong> <?php echo htmlspecialchars($supervisor['department']); ?></p>
                            <p><strong>Assigned Coordinators:</strong> <?php echo count($assigned_coordinators); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Coordinator assignments are managed by the Dean. This page is read-only for EDP users.
            </div>

            <!-- Current Assignments -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Current Coordinator Assignments</h5>
                </div>
                <div class="card-body">
                    <?php if(empty($assigned_coordinators)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users fa-3x mb-3"></i>
                            <h5>No Coordinators Assigned</h5>
                            <p>Coordinator assignments are managed by the Dean.</p>
                        </div>
                    <?php else: ?>
                        <div class="assignment-card">
                            <div class="assignment-body">
                                <ul class="coordinator-list">
                                    <?php foreach($assigned_coordinators as $assignment): ?>
                                    <li class="coordinator-item">
                                        <div>
                                            <strong><?php echo htmlspecialchars($assignment['coordinator_name']); ?></strong>
                                            <span class="role-badge"><?php echo ucfirst(str_replace('_', ' ', $assignment['coordinator_role'])); ?></span>
                                            <small class="text-muted ms-2"><?php echo htmlspecialchars($assignment['department']); ?></small>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>