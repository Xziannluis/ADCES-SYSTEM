<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/User.php';
require_once '../includes/program_assignments.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);
$user = new User($db);

// Check if viewing coordinator's teachers (for supervisors)
$viewing_coordinator = false;
$coordinator_info = null;
if(isset($_GET['evaluator_id']) && in_array($_SESSION['role'], ['dean', 'principal'])) {
    $evaluator_id = $_GET['evaluator_id'];
    $coordinator_info = $user->getById($evaluator_id);
    
    // Verify the coordinator is assigned to the supervisor
    $check_assignment = "SELECT id FROM evaluator_assignments WHERE evaluator_id = :evaluator_id AND supervisor_id = :supervisor_id";
    $check_stmt = $db->prepare($check_assignment);
    $check_stmt->bindParam(':evaluator_id', $evaluator_id);
    $check_stmt->bindParam(':supervisor_id', $_SESSION['user_id']);
    $check_stmt->execute();
    
    if($check_stmt->rowCount() > 0 && $coordinator_info) {
        $viewing_coordinator = true;
        $current_evaluator_id = $evaluator_id;
    } else {
        $_SESSION['error'] = "Invalid coordinator or access denied.";
        header('Location: assign_coordinators.php');
        exit();
    }
} else {
    $current_evaluator_id = $_SESSION['user_id'];
}

// Determine target evaluator (may be the current user or a coordinator being viewed by a supervisor)
$evaluator_specializations = [];
$target_evaluator_id = $current_evaluator_id;
$target_role = $coordinator_info['role'] ?? $_SESSION['role'];
$target_programs = resolveEvaluatorPrograms(
    $db,
    $target_evaluator_id,
    $coordinator_info['department'] ?? $_SESSION['department']
);

if (in_array($target_role, ['subject_coordinator', 'chairperson'])) {
    $subjects_query = "SELECT subject FROM evaluator_subjects WHERE evaluator_id = :evaluator_id";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->bindParam(':evaluator_id', $target_evaluator_id);
    $subjects_stmt->execute();
    $evaluator_specializations = $subjects_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} elseif ($target_role === 'grade_level_coordinator') {
    $grades_query = "SELECT grade_level FROM evaluator_grade_levels WHERE evaluator_id = :evaluator_id";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':evaluator_id', $target_evaluator_id);
    $grades_stmt->execute();
    $evaluator_specializations = $grades_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}
        // Coordinators may assign teachers for themselves; only deans/principals may assign on behalf of others.

// Handle teacher assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_teacher') {
    // Principals mirror dean behavior for elementary/school flows
    $canAssignSelf = false;
    $canAssignOthers = in_array($_SESSION['role'], ['dean', 'principal']);
    if (!$canAssignSelf && !$canAssignOthers) {
        $_SESSION['error'] = "You are not allowed to assign teachers.";
        header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
        exit();
    }

    $teacher_id = $_POST['teacher_id'];
    // Allow supervisor to pick target coordinator
    $assign_target_evaluator_id = $current_evaluator_id;
    if ($canAssignOthers && !empty($_POST['target_evaluator_id'])) {
        $possible_target = (int)$_POST['target_evaluator_id'];
        // validate target is a coordinator in the same department
        $v_query = "SELECT id, role FROM users WHERE id = :id AND role IN ('chairperson','subject_coordinator','grade_level_coordinator') AND department = :dept AND status = 'active' LIMIT 1";
        $v_stmt = $db->prepare($v_query);
        $v_stmt->bindParam(':id', $possible_target);
        $v_stmt->bindParam(':dept', $_SESSION['department']);
        $v_stmt->execute();
        if ($v_stmt->rowCount() > 0) {
            $assign_target_evaluator_id = $possible_target;
        } else {
            $_SESSION['error'] = "Invalid target coordinator selected.";
            header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
            exit();
        }
    }
    
    // Ensure teacher is within coordinator's program assignments (primary or secondary department)
    if (!empty($target_programs)) {
        $deptCheck = $db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
        $deptCheck->bindParam(':id', $teacher_id);
        $deptCheck->execute();
        $teacherDept = $deptCheck->fetchColumn();

        $inProgram = ($teacherDept !== false && in_array($teacherDept, $target_programs, true));

        // Also check secondary departments from teacher_departments table
        if (!$inProgram) {
            try {
                $placeholders = [];
                $params = [':tid' => $teacher_id];
                foreach ($target_programs as $i => $prog) {
                    $key = ':prog_' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $prog;
                }
                $secCheck = $db->prepare("SELECT 1 FROM teacher_departments WHERE teacher_id = :tid AND department IN (" . implode(',', $placeholders) . ") LIMIT 1");
                $secCheck->execute($params);
                $inProgram = ($secCheck->rowCount() > 0);
            } catch (PDOException $e) {}
        }

        if (!$inProgram) {
            $_SESSION['error'] = "Selected teacher is outside the coordinator's assigned program.";
            header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
            exit();
        }
    }

    // Check if assignment already exists
    $check_query = "SELECT id FROM teacher_assignments WHERE evaluator_id = :evaluator_id AND teacher_id = :teacher_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':evaluator_id', $assign_target_evaluator_id);
    $check_stmt->bindParam(':teacher_id', $teacher_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() === 0) {
        // Prevent assigning a teacher who is already assigned to a different chairperson
        $chair_check_query = "SELECT ta.id FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE u.role = 'chairperson' AND ta.teacher_id = :teacher_id AND ta.evaluator_id != :evaluator_id LIMIT 1";
        $chair_check_stmt = $db->prepare($chair_check_query);
        $chair_check_stmt->bindParam(':teacher_id', $teacher_id);
        $chair_check_stmt->bindParam(':evaluator_id', $assign_target_evaluator_id);
        $chair_check_stmt->execute();
        if ($chair_check_stmt->rowCount() > 0) {
            $_SESSION['error'] = "Teacher is already assigned to a chairperson and cannot be reassigned.";
            header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
            exit();
        }
    $insert_query = "INSERT INTO teacher_assignments (evaluator_id, teacher_id, assigned_at) 
        VALUES (:evaluator_id, :teacher_id, NOW())";
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':evaluator_id', $assign_target_evaluator_id);
    $insert_stmt->bindParam(':teacher_id', $teacher_id);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success'] = "Teacher assigned successfully!";
        } else {
            $_SESSION['error'] = "Failed to assign teacher.";
        }
    } else {
        $_SESSION['error'] = "Teacher is already assigned to this evaluator.";
    }
    
    header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
    exit();
}

// Handle teacher removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assignment') {
    // Principals mirror dean behavior for elementary/school flows
    $canRemoveSelf = false;
    $canRemoveOthers = in_array($_SESSION['role'], ['dean', 'principal']);
    if (!$canRemoveSelf && !$canRemoveOthers) {
        $_SESSION['error'] = "You are not allowed to remove assignments.";
        header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
        exit();
    }

    $assignment_id = $_POST['assignment_id'];
    
    $delete_query = "DELETE FROM teacher_assignments WHERE id = :assignment_id AND evaluator_id = :evaluator_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':assignment_id', $assignment_id);
    $delete_stmt->bindParam(':evaluator_id', $current_evaluator_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "Teacher assignment removed successfully!";
    } else {
        $_SESSION['error'] = "Failed to remove teacher assignment.";
    }
    
    header("Location: assign_teachers.php" . ($viewing_coordinator ? "?evaluator_id=" . $current_evaluator_id : ""));
    exit();
}

// Get assigned teachers (scoped to program assignments when available)
$assigned_query = "SELECT ta.*, t.name as teacher_name, t.department 
                  FROM teacher_assignments ta 
                  JOIN teachers t ON ta.teacher_id = t.id 
                  WHERE ta.evaluator_id = :evaluator_id";
if (!empty($target_programs)) {
    $programPlaceholders = [];
    foreach ($target_programs as $idx => $dept) {
        $programPlaceholders[] = ':program_' . $idx;
    }
    $assigned_query .= " AND t.department IN (" . implode(',', $programPlaceholders) . ")";
}
$assigned_query .= " ORDER BY ta.subject, ta.grade_level, t.name";
$assigned_stmt = $db->prepare($assigned_query);
$assigned_stmt->bindParam(':evaluator_id', $current_evaluator_id);
if (!empty($target_programs)) {
    foreach ($target_programs as $idx => $dept) {
        $assigned_stmt->bindValue(':program_' . $idx, $dept);
    }
}
$assigned_stmt->execute();
$assigned_teachers = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available teachers scoped to the evaluator's program assignments
$available_teachers = $teacher->getActiveByDepartments($target_programs);

// If current user is a supervisor, load coordinators list for dropdown
$coordinators = [];
if (in_array($_SESSION['role'], ['dean', 'principal'])) {
    $coord_query = "SELECT id, name, role FROM users WHERE role IN ('subject_coordinator','chairperson','grade_level_coordinator') AND department = :dept AND status = 'active' ORDER BY role, name";
    $coord_stmt = $db->prepare($coord_query);
    $coord_stmt->bindParam(':dept', $_SESSION['department']);
    $coord_stmt->execute();
    $coordinators = $coord_stmt->fetchAll(PDO::FETCH_ASSOC);
}
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
            .form-container,
            .assignment-header,
            .assignment-body {
                padding: 1rem;
            }

            .teacher-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.35rem;
            }

            .form-container .row > div {
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>
                    <?php if($viewing_coordinator): ?>
                        Teachers Assigned to <?php echo htmlspecialchars($coordinator_info['name']); ?>
                    <?php else: ?>
                        Assign Teachers - <?php echo htmlspecialchars($_SESSION['department']); ?>
                    <?php endif; ?>
                </h3>
            </div>

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
                    <h5 class="mb-0">
                        <i class="fas fa-user-tie me-2"></i>
                        <?php echo $viewing_coordinator ? 'Coordinator Information' : 'My Information'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Name:</strong> 
                                <?php echo $viewing_coordinator ? htmlspecialchars($coordinator_info['name']) : htmlspecialchars($_SESSION['name']); ?>
                            </p>
                            <p><strong>Role:</strong> 
                                <?php echo $viewing_coordinator ? 
                                    ucfirst(str_replace('_', ' ', $coordinator_info['role'])) : 
                                    ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Department:</strong> <?php echo htmlspecialchars($_SESSION['department']); ?></p>
                            <p><strong>Assigned Teachers:</strong> <?php echo count($assigned_teachers); ?></p>
                        </div>
                    </div>
                    <?php if(!empty($evaluator_specializations)): ?>
                    <div class="row mt-3">
                        <div class="col-12">
                            <p><strong>Specializations:</strong> 
                                <?php echo implode(', ', $evaluator_specializations); ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Assign New Teacher -->
            <?php if(in_array($_SESSION['role'], ['dean', 'principal'])): ?>
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
                                    <?php while($teacher_row = $available_teachers->fetch(PDO::FETCH_ASSOC)):
                                        // Exclude teachers who are evaluators/admins in the TARGET coordinator's department
                                        // but allow them if they teach in other departments (cross-dept assignment)
                                        $exclude = false;
                                        $target_dept_for_exclude = $coordinator_info['department'] ?? $_SESSION['department'];
                                        if (!empty($teacher_row['user_id'])) {
                                            $eval_query = $db->prepare("SELECT 1 FROM users WHERE id = :uid AND role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator') AND department = :dept AND status = 'active' LIMIT 1");
                                            $eval_query->bindParam(':uid', $teacher_row['user_id']);
                                            $eval_query->bindParam(':dept', $target_dept_for_exclude);
                                            $eval_query->execute();
                                            if ($eval_query->fetchColumn()) {
                                                $exclude = true;
                                            }
                                        } else {
                                            // Fallback: match by name within the target department
                                            $eval_query = $db->prepare("SELECT 1 FROM users WHERE role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator') AND department = :dept AND name = :name AND status = 'active' LIMIT 1");
                                            $eval_query->bindParam(':dept', $target_dept_for_exclude);
                                            $eval_query->bindParam(':name', $teacher_row['name']);
                                            $eval_query->execute();
                                            if ($eval_query->fetchColumn()) {
                                                $exclude = true;
                                            }
                                        }

                                        // Respect evaluator specializations when present (e.g., an IT chairperson should only see IT teachers)
                                        $include_by_specialization = true;
                                        $include_by_department = true;

                                        // Use the TARGET coordinator's department, not the logged-in user's
                                        $coord_dept = strtolower(trim($coordinator_info['department'] ?? $_SESSION['department']));
                                        $teacher_dept = strtolower(trim($teacher_row['department'] ?? ''));

                                        // Check if this teacher has a secondary department matching the coordinator's programs
                                        $teacher_matches_target = false;
                                        if (!empty($target_programs)) {
                                            foreach ($target_programs as $tp) {
                                                if (strtolower(trim($tp)) === $teacher_dept) {
                                                    $teacher_matches_target = true;
                                                    break;
                                                }
                                            }
                                            // Also check secondary departments via teacher_departments
                                            if (!$teacher_matches_target && !empty($teacher_row['id'])) {
                                                try {
                                                    $prog_placeholders = [];
                                                    foreach ($target_programs as $idx => $tp) {
                                                        $prog_placeholders[] = ':prog_' . $idx;
                                                    }
                                                    $sec_dept_check = $db->prepare("SELECT 1 FROM teacher_departments WHERE teacher_id = :tid AND department IN (" . implode(',', $prog_placeholders) . ") LIMIT 1");
                                                    $sec_dept_check->bindParam(':tid', $teacher_row['id']);
                                                    foreach ($target_programs as $idx => $tp) {
                                                        $sec_dept_check->bindValue(':prog_' . $idx, $tp);
                                                    }
                                                    $sec_dept_check->execute();
                                                    if ($sec_dept_check->fetchColumn()) {
                                                        $teacher_matches_target = true;
                                                    }
                                                } catch (Throwable $e) {
                                                    // teacher_departments may not exist; treat as not matching
                                                }
                                            }
                                        } else {
                                            // No specific programs; trust the upstream query
                                            $teacher_matches_target = true;
                                        }

                                        // If teacher is in a target program (primary or secondary), skip the hard-coded dept filter
                                        if (!$teacher_matches_target) {
                                            $include_by_department = false;
                                        }

                                        if (!empty($evaluator_specializations) && !$teacher_matches_target) {
                                            $specs = array_map('strtolower', $evaluator_specializations);
                                            // If evaluator specializes in IT, only include teachers whose department mentions IT
                                            if (in_array('it', $specs) || in_array('information technology', $specs) || in_array('information_technology', $specs)) {
                                                $include_by_specialization = (strpos($teacher_dept, 'it') !== false || strpos($teacher_dept, 'information technology') !== false);
                                            }

                                            // If evaluator specializes in CCIS, only include CCIS teachers
                                            if (in_array('ccis', $specs) || in_array('computer science', $specs) || in_array('cs', $specs)) {
                                                $include_by_specialization = (strpos($teacher_dept, 'ccis') !== false || strpos($teacher_dept, 'computer science') !== false || strpos($teacher_dept, 'cs') !== false);
                                            }
                                        }

                                        if (!$exclude && $include_by_specialization && $include_by_department):
                                    ?>
                                        <option value="<?php echo $teacher_row['id']; ?>">
                                            <?php echo htmlspecialchars($teacher_row['name']); ?>
                                        </option>
                                    <?php endif; endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <div class="mb-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Assign Teacher
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

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
                            <p>
                                <?php if($viewing_coordinator): ?>
                                    This coordinator hasn't assigned any teachers yet.
                                <?php else: ?>
                                    Use the form above to assign teachers to evaluate.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php
                        // Group assignments by subject/grade level
                        $grouped_assignments = [];
                        foreach ($assigned_teachers as $assignment) {
                            $key = $assignment['subject'] ?: $assignment['grade_level'];
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
                                        <?php if(in_array($_SESSION['role'], ['dean', 'principal'])): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Remove <?php echo htmlspecialchars($assignment['teacher_name']); ?> from <?php echo htmlspecialchars($category); ?>?')">
                                                <i class="fas fa-times"></i> Remove
                                            </button>
                                        </form>
                                        <?php endif; ?>
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

    <?php include '../includes/footer.php'; ?>
</body>
</html>