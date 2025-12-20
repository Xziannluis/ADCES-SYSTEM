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

// Get evaluator's subjects or grade levels
$evaluator_specializations = [];
if (in_array($evaluator['role'], ['subject_coordinator', 'chairperson'])) {
    $subjects_query = "SELECT subject FROM evaluator_subjects WHERE evaluator_id = :evaluator_id";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->bindParam(':evaluator_id', $evaluator_id);
    $subjects_stmt->execute();
    $evaluator_specializations = $subjects_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} elseif ($evaluator['role'] === 'grade_level_coordinator') {
    $grades_query = "SELECT grade_level FROM evaluator_grade_levels WHERE evaluator_id = :evaluator_id";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':evaluator_id', $evaluator_id);
    $grades_stmt->execute();
    $evaluator_specializations = $grades_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Handle teacher assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_teacher') {
    $teacher_id = $_POST['teacher_id'];
    $subject = $_POST['subject'] ?? '';
    $grade_level = $_POST['grade_level'] ?? '';
    
    // Check if assignment already exists
    $check_query = "SELECT id FROM teacher_assignments WHERE evaluator_id = :evaluator_id AND teacher_id = :teacher_id";
    if (!empty($subject)) {
        $check_query .= " AND subject = :subject";
    } elseif (!empty($grade_level)) {
        $check_query .= " AND grade_level = :grade_level";
    }
    
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':evaluator_id', $evaluator_id);
    $check_stmt->bindParam(':teacher_id', $teacher_id);
    if (!empty($subject)) {
        $check_stmt->bindParam(':subject', $subject);
    } elseif (!empty($grade_level)) {
        $check_stmt->bindParam(':grade_level', $grade_level);
    }
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() === 0) {
        $insert_query = "INSERT INTO teacher_assignments (evaluator_id, teacher_id, subject, grade_level, assigned_at) 
                        VALUES (:evaluator_id, :teacher_id, :subject, :grade_level, NOW())";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':evaluator_id', $evaluator_id);
        $insert_stmt->bindParam(':teacher_id', $teacher_id);
        $insert_stmt->bindParam(':subject', $subject);
        $insert_stmt->bindParam(':grade_level', $grade_level);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success'] = "Teacher assigned successfully!";
        } else {
            $_SESSION['error'] = "Failed to assign teacher.";
        }
    } else {
        $_SESSION['error'] = "Teacher is already assigned to this evaluator.";
    }
    
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

// Get available teachers (not assigned to this evaluator for the same subject)
$available_teachers = $teacher->getActiveByDepartment($evaluator['department']);
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
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Assign Teachers to <?php echo htmlspecialchars($evaluator['name']); ?></h3>
                <a href="users.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Users
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

            <!-- Assign New Teacher -->
            <div class="form-container">
                <h5><i class="fas fa-plus-circle me-2"></i>Assign New Teacher</h5>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_teacher">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Teacher</label>
                                <select class="form-select" name="teacher_id" required>
                                    <option value="">Select Teacher</option>
                                    <?php while($teacher_row = $available_teachers->fetch(PDO::FETCH_ASSOC)): ?>
                                        <option value="<?php echo $teacher_row['id']; ?>"><?php echo htmlspecialchars($teacher_row['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <?php if (in_array($evaluator['role'], ['subject_coordinator', 'chairperson'])): ?>
                            <div class="mb-3">
                                <label class="form-label">Subject</label>
                                <select class="form-select" name="subject" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach($evaluator_specializations as $subject): ?>
                                        <option value="<?php echo htmlspecialchars($subject); ?>"><?php echo htmlspecialchars($subject); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php elseif ($evaluator['role'] === 'grade_level_coordinator'): ?>
                            <div class="mb-3">
                                <label class="form-label">Grade Level</label>
                                <select class="form-select" name="grade_level" required>
                                    <option value="">Select Grade Level</option>
                                    <?php foreach($evaluator_specializations as $grade): ?>
                                        <option value="<?php echo htmlspecialchars($grade); ?>">Grade <?php echo htmlspecialchars($grade); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Assignment Type</label>
                                <select class="form-select" name="assignment_type" required>
                                    <option value="general">General Evaluation</option>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Assign Teacher
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
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
                            <p>Use the form above to assign teachers to this evaluator.</p>
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
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Remove <?php echo htmlspecialchars($assignment['teacher_name']); ?> from <?php echo htmlspecialchars($category); ?>?')">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </form>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>