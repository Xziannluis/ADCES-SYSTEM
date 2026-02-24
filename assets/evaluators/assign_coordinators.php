<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/User.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

// Handle coordinator assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_coordinator') {
    $coordinator_id = $_POST['coordinator_id'];
    $supervisor_id = $_SESSION['user_id'];
    $program = trim($_POST['program'] ?? '');
    if ($program === '') {
        $program = $_SESSION['department'] ?? '';
    }
    
    // Check if assignment already exists
    $check_query = "SELECT id FROM evaluator_assignments WHERE evaluator_id = :coordinator_id AND supervisor_id = :supervisor_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':coordinator_id', $coordinator_id);
    $check_stmt->bindParam(':supervisor_id', $supervisor_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() === 0) {
    $insert_query = "INSERT INTO evaluator_assignments (evaluator_id, supervisor_id, program, assigned_at) 
            VALUES (:evaluator_id, :supervisor_id, :program, NOW())";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':evaluator_id', $coordinator_id);
        $insert_stmt->bindParam(':supervisor_id', $supervisor_id);
    $insert_stmt->bindParam(':program', $program);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success'] = "Coordinator assigned successfully!";
        } else {
            $_SESSION['error'] = "Failed to assign coordinator.";
        }
    } else {
        $_SESSION['error'] = "This coordinator is already assigned and cannot be assigned again.";
    }
    
    header("Location: assign_coordinators.php");
    exit();
}

// Handle coordinator removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assignment') {
    $assignment_id = $_POST['assignment_id'];
    
    $delete_query = "DELETE FROM evaluator_assignments WHERE id = :assignment_id AND supervisor_id = :supervisor_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':assignment_id', $assignment_id);
    $delete_stmt->bindParam(':supervisor_id', $_SESSION['user_id']);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Coordinator assignment removed successfully!";
    } else {
        $_SESSION['error'] = "Failed to remove coordinator assignment.";
    }
    
    header("Location: assign_coordinators.php");
    exit();
}

// Get assigned coordinators
$assigned_query = "SELECT ea.*, u.name as coordinator_name, u.role as coordinator_role, u.department 
                  FROM evaluator_assignments ea 
                  JOIN users u ON ea.evaluator_id = u.id 
                  WHERE ea.supervisor_id = :supervisor_id 
                  ORDER BY u.role, u.name";
$assigned_stmt = $db->prepare($assigned_query);
$assigned_stmt->bindParam(':supervisor_id', $_SESSION['user_id']);
$assigned_stmt->execute();
$assigned_coordinators = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available coordinators (not assigned to this supervisor, same department, and active)
$available_query = "SELECT u.id, u.name, u.role, u.department 
                   FROM users u 
                   WHERE u.role IN ('subject_coordinator', 'chairperson', 'grade_level_coordinator') 
                   AND u.department = :department
                   AND u.status = 'active' 
                   AND u.id NOT IN (
                       SELECT evaluator_id FROM evaluator_assignments WHERE supervisor_id = :supervisor_id
                   )
                   ORDER BY u.role, u.name";
$available_stmt = $db->prepare($available_query);
$available_stmt->bindParam(':department', $_SESSION['department']);
$available_stmt->bindParam(':supervisor_id', $_SESSION['user_id']);
$available_stmt->execute();
$available_coordinators = $available_stmt->fetchAll(PDO::FETCH_ASSOC);
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
        .form-container .form-label {
            font-size: 0.9rem;
            margin-bottom: 6px;
        }
        .form-container .form-select {
            font-size: 0.9rem;
            padding: 6px 10px;
            min-height: 36px;
            max-width: 520px;
        }
        .form-container .btn {
            padding: 6px 14px;
            font-size: 0.9rem;
        }
        .role-badge {
            background-color: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Assign Coordinators - <?php echo $_SESSION['department']; ?></h3>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>

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
                    <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>My Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($_SESSION['name']); ?></p>
                            <p><strong>Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Department:</strong> <?php echo htmlspecialchars($_SESSION['department']); ?></p>
                            <p><strong>Assigned Coordinators:</strong> <?php echo count($assigned_coordinators); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assign New Coordinator -->
            <div class="form-container">
                <h5><i class="fas fa-plus-circle me-2"></i>Assign New Coordinator</h5>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_coordinator">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Coordinator</label>
                                <select class="form-select" name="coordinator_id" required>
                                    <option value="">Select Coordinator</option>
                                    <?php foreach($available_coordinators as $coordinator): ?>
                                        <option value="<?php echo $coordinator['id']; ?>">
                                            <?php echo htmlspecialchars($coordinator['name']); ?> 
                                            (<?php echo ucfirst(str_replace('_', ' ', $coordinator['role'])); ?> - <?php echo htmlspecialchars($coordinator['department']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Assign Coordinator
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
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
                            <p>Use the form above to assign coordinators to supervise.</p>
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
                                        <div>
                                            <a href="assign_teachers.php?evaluator_id=<?php echo $assignment['evaluator_id']; ?>" class="btn btn-sm btn-info me-2">
                                                <i class="fas fa-eye me-1"></i>View Teachers
                                            </a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_assignment">
                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Remove <?php echo htmlspecialchars($assignment['coordinator_name']); ?> from your assignments?')">
                                                    <i class="fas fa-times"></i> Remove
                                                </button>
                                            </form>
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

    <?php include '../includes/footer.php'; ?>
</body>
</html>