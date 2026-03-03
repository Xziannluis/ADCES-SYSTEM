<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/program_assignments.php';
require_once '../includes/mailer.php';

$database = new Database();
$db = $database->getConnection();

$teacher = new Teacher($db);

// Handle teacher actions
$action = $_GET['action'] ?? '';
$success_message = '';
$error_message = '';

// Toggle teacher status (activate/deactivate)
if ($_GET && isset($_GET['action']) && $_GET['action'] === 'toggle_status') {
    $teacher_id = $_GET['teacher_id'] ?? '';
    if (!empty($teacher_id)) {
        if ($teacher->toggleStatus($teacher_id)) {
            $success_message = "Teacher status updated successfully!";
        } else {
            $error_message = "Failed to update teacher status.";
        }
    }
    // Redirect to avoid POST/GET issues
    header("Location: teachers.php");
    exit();
}

// Update evaluation schedule and room
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    $teacher_id = $_POST['teacher_id'] ?? '';
    $schedule = $_POST['evaluation_schedule'] ?? '';
    $room = $_POST['evaluation_room'] ?? '';
    
    if (!empty($teacher_id)) {
        // Update using a query to add/update schedule and room info
        $query = "UPDATE teachers SET evaluation_schedule = :schedule, evaluation_room = :room, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':schedule', $schedule);
        $stmt->bindParam(':room', $room);
        $stmt->bindParam(':id', $teacher_id);
        
        if ($stmt->execute()) {
            $success_message = "Evaluation schedule and room updated successfully!";
            // If the updated teacher record is linked to a coordinator user, create a notification (audit log)
            try {
                $tq = $db->prepare("SELECT user_id, name, email FROM teachers WHERE id = :id LIMIT 1");
                $tq->bindParam(':id', $teacher_id);
                $tq->execute();
                $tdata = $tq->fetch(PDO::FETCH_ASSOC);
                if ($tdata && !empty($tdata['email']) && (!empty($schedule) || !empty($room))) {
                    $sent = sendScheduleNotificationEmail(
                        $tdata['email'],
                        $tdata['name'] ?? 'Teacher',
                        $schedule,
                        $room,
                        $_SESSION['name'] ?? 'Evaluator'
                    );
                    if (!$sent) {
                        error_log('Schedule email was not sent (mailer disabled or failed).');
                    }
                }
                if ($tdata && !empty($tdata['user_id'])) {
                    $uid = $tdata['user_id'];
                    $uq = $db->prepare("SELECT id, name, role FROM users WHERE id = :id LIMIT 1");
                    $uq->bindParam(':id', $uid);
                    $uq->execute();
                    $uinfo = $uq->fetch(PDO::FETCH_ASSOC);
                    if ($uinfo && in_array($uinfo['role'], ['chairperson','subject_coordinator','grade_level_coordinator'])) {
                        $description = sprintf("Schedule set: %s in %s. Set by %s (user_id=%d)", $schedule ?: 'N/A', $room ?: 'N/A', $_SESSION['name'], $_SESSION['user_id']);
                        $log_q = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)");
                        $action = 'SCHEDULE_ASSIGNED';
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $log_q->bindParam(':user_id', $uid);
                        $log_q->bindParam(':action', $action);
                        $log_q->bindParam(':description', $description);
                        $log_q->bindParam(':ip', $ip);
                        $log_q->execute();
                    }
                }
            } catch (Exception $e) {
                error_log('Schedule notification error: ' . $e->getMessage());
            }
        } else {
            $error_message = "Failed to update schedule and room.";
        }
    } else {
        $error_message = "Teacher ID is required.";
    }
}

// Cancel / clear evaluation schedule and room
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'cancel_schedule') {
    $teacher_id = $_POST['teacher_id'] ?? '';

    if (!empty($teacher_id)) {
        $query = "UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $teacher_id);

        if ($stmt->execute()) {
            $success_message = "Evaluation schedule cancelled.";

            // Log for auditing/notifications
            try {
                $tq = $db->prepare("SELECT user_id, name FROM teachers WHERE id = :id LIMIT 1");
                $tq->bindParam(':id', $teacher_id);
                $tq->execute();
                $tdata = $tq->fetch(PDO::FETCH_ASSOC);
                $uid = $tdata['user_id'] ?? null;

                $description = sprintf(
                    "Schedule cancelled for %s. Cancelled by %s (user_id=%d)",
                    $tdata['name'] ?? ('teacher_id=' . $teacher_id),
                    $_SESSION['name'],
                    $_SESSION['user_id']
                );

                $log_q = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)");
                $action = 'SCHEDULE_CANCELLED';
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $log_q->bindValue(':user_id', $uid ?: $_SESSION['user_id']);
                $log_q->bindParam(':action', $action);
                $log_q->bindParam(':description', $description);
                $log_q->bindParam(':ip', $ip);
                $log_q->execute();
            } catch (Exception $e) {
                error_log('Schedule cancel log error: ' . $e->getMessage());
            }
        } else {
            $error_message = "Failed to cancel schedule.";
        }
    } else {
        $error_message = "Teacher ID is required.";
    }
}

// Mark evaluation done (lightweight: clears schedule/room; actual completed evaluations are stored in `evaluations`)
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'mark_done') {
    $teacher_id = $_POST['teacher_id'] ?? '';

    if (!empty($teacher_id)) {
        try {
            $db->beginTransaction();

            // 1) Clear schedule/room (so teacher dashboard's schedule banner is removed)
            $query = "UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $teacher_id);
            $stmt->execute();

            // 2) Mark the latest evaluation record as completed (so teacher dashboard status isn't Pending)
            // Assumption: "done" means the most recently created evaluation for this teacher is now finalized.
            $latestEvalStmt = $db->prepare(
                "SELECT id, status FROM evaluations WHERE teacher_id = :teacher_id ORDER BY created_at DESC, id DESC LIMIT 1"
            );
            $latestEvalStmt->bindParam(':teacher_id', $teacher_id);
            $latestEvalStmt->execute();
            $latestEval = $latestEvalStmt->fetch(PDO::FETCH_ASSOC);

            if ($latestEval) {
                $updateEvalStmt = $db->prepare(
                    "UPDATE evaluations SET status = 'completed' WHERE id = :id AND (status IS NULL OR status <> 'completed')"
                );
                $updateEvalStmt->bindParam(':id', $latestEval['id']);
                $updateEvalStmt->execute();
            }

            // 3) Log for auditing/notifications
            $tq = $db->prepare("SELECT user_id, name FROM teachers WHERE id = :id LIMIT 1");
            $tq->bindParam(':id', $teacher_id);
            $tq->execute();
            $tdata = $tq->fetch(PDO::FETCH_ASSOC);
            $uid = $tdata['user_id'] ?? null;

            $description = sprintf(
                "Evaluation marked done for %s by %s (user_id=%d)%s",
                $tdata['name'] ?? ('teacher_id=' . $teacher_id),
                $_SESSION['name'],
                $_SESSION['user_id'],
                $latestEval ? (sprintf("; evaluation_id=%d", (int)$latestEval['id'])) : '; no evaluation record found'
            );

            $log_q = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)");
            $action = 'EVALUATION_MARKED_DONE';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $log_q->bindValue(':user_id', $uid ?: $_SESSION['user_id']);
            $log_q->bindParam(':action', $action);
            $log_q->bindParam(':description', $description);
            $log_q->bindParam(':ip', $ip);
            $log_q->execute();

            $db->commit();

            if ($latestEval) {
                $success_message = "Marked as evaluated. Evaluation status updated.";
            } else {
                $success_message = "Marked as evaluated. Schedule cleared (no evaluation record found to update).";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Mark done error: ' . $e->getMessage());
            $error_message = "Failed to mark as done.";
        }
    } else {
        $error_message = "Teacher ID is required.";
    }
}

// Handle teacher assignment (only dean may assign)
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'assign_teacher') {
    if ($_SESSION['role'] !== 'dean') {
        $error_message = "You are not allowed to assign teachers.";
    } else {
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
        $check_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
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
            $insert_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
            $insert_stmt->bindParam(':teacher_id', $teacher_id);
            $insert_stmt->bindParam(':subject', $subject);
            $insert_stmt->bindParam(':grade_level', $grade_level);
            
            if ($insert_stmt->execute()) {
                $success_message = "Teacher assigned successfully!";
            } else {
                $error_message = "Failed to assign teacher.";
            }
        } else {
            $error_message = "Teacher is already assigned to you.";
        }
    }
}

// Handle teacher removal (only dean may remove assignments)
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'remove_assignment') {
    $assignment_id = $_POST['assignment_id'];
    
    if ($_SESSION['role'] !== 'dean') {
        $error_message = "You are not allowed to remove assignments.";
    } else {
        // Deans/Principals may remove any assignment by id
        $delete_query = "DELETE FROM teacher_assignments WHERE id = :assignment_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':assignment_id', $assignment_id);
        
        if ($delete_stmt->execute()) {
            $success_message = "Teacher assignment removed successfully!";
        } else {
            $error_message = "Failed to remove teacher assignment.";
        }
    }
}

// Get teachers for current department (or only assigned teachers for coordinators)
if (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    $programs = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $_SESSION['department'] ?? null);
    $assigned_query = "SELECT t.* FROM teachers t JOIN teacher_assignments ta ON ta.teacher_id = t.id WHERE ta.evaluator_id = :evaluator_id";
    if (!empty($programs)) {
        $programPlaceholders = [];
        foreach ($programs as $idx => $dept) {
            $programPlaceholders[] = ':program_' . $idx;
        }
        $assigned_query .= " AND t.department IN (" . implode(',', $programPlaceholders) . ")";
    }
    $assigned_query .= " ORDER BY t.name";
    $stmt = $db->prepare($assigned_query);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    if (!empty($programs)) {
        foreach ($programs as $idx => $dept) {
            $stmt->bindValue(':program_' . $idx, $dept);
        }
    }
    $stmt->execute();
    $teachers = $stmt; // keep interface similar (PDOStatement)
} else {
    // Deans/principals and others see full department list
    $teachers = $teacher->getByDepartment($_SESSION['department']);
}

// Get assigned teachers for current evaluator
$assigned_query = "SELECT ta.*, t.name as teacher_name, t.department, t.evaluation_schedule, t.evaluation_room
                  FROM teacher_assignments ta 
                  JOIN teachers t ON ta.teacher_id = t.id 
                  WHERE ta.evaluator_id = :evaluator_id";
if (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    $programs = $programs ?? resolveEvaluatorPrograms($db, $_SESSION['user_id'], $_SESSION['department'] ?? null);
    if (!empty($programs)) {
        $programPlaceholders = [];
        foreach ($programs as $idx => $dept) {
            $programPlaceholders[] = ':assigned_program_' . $idx;
        }
        $assigned_query .= " AND t.department IN (" . implode(',', $programPlaceholders) . ")";
    }
}
$assigned_query .= " ORDER BY ta.subject, ta.grade_level, t.name";
$assigned_stmt = $db->prepare($assigned_query);
$assigned_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
if (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator']) && !empty($programs)) {
    foreach ($programs as $idx => $dept) {
        $assigned_stmt->bindValue(':assigned_program_' . $idx, $dept);
    }
}
$assigned_stmt->execute();
$assigned_teachers = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get evaluator's subjects or grade levels
$evaluator_specializations = [];
if (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson'])) {
    $subjects_query = "SELECT subject FROM evaluator_subjects WHERE evaluator_id = :evaluator_id";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $subjects_stmt->execute();
    $evaluator_specializations = $subjects_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} elseif ($_SESSION['role'] === 'grade_level_coordinator') {
    $grades_query = "SELECT grade_level FROM evaluator_grade_levels WHERE evaluator_id = :evaluator_id";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $grades_stmt->execute();
    $evaluator_specializations = $grades_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Get a single teacher for editing (AJAX)
if (isset($_GET['get_teacher']) && isset($_GET['id'])) {
    $teacher_data = $teacher->getById($_GET['id']);
    header('Content-Type: application/json');
    if ($teacher_data) {
        echo json_encode(['success' => true, 'teacher' => $teacher_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Teacher not found']);
    }
    exit();
}

// Get assigned coordinators (for deans/principals)
$assigned_coordinators = [];
if (in_array($_SESSION['role'], ['dean', 'principal'])) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers - <?php echo $_SESSION['department']; ?></title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .teacher-card {
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .teacher-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .teacher-photo-section {
            position: relative;
            height: 180px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .teacher-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .default-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid white;
        }
        
        .default-photo i {
            font-size: 2.5rem;
            color: white;
        }
        
        .teacher-info {
            padding: 20px;
            text-align: center;
        }
        
        .teacher-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        .teacher-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }

        .teacher-actions {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .teacher-actions .btn {
            flex: 1;
            min-width: 80px;
            font-size: 0.75rem;
            padding: 5px 10px;
        }

        .modal-body .form-group {
            margin-bottom: 15px;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .modal-lg {
            max-width: 600px;
        }

        .schedule-modal .modal-header {
            background: linear-gradient(135deg, #1b66c9, #0f4fa8);
            color: #fff;
            border-bottom: none;
        }

        .schedule-modal .modal-title {
            font-weight: 600;
        }

        .schedule-modal .modal-body {
            padding: 20px 22px 8px;
        }

        .schedule-modal .modal-footer {
            border-top: none;
            padding: 12px 22px 20px;
        }

        .schedule-card {
            border: 1px solid #e6eef9;
            background: #f7fbff;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .schedule-field label {
            font-weight: 600;
        }

        .schedule-help {
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .schedule-preview {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .schedule-preview .preview-chip {
            background: #fff;
            border: 1px solid #d7e3f7;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            color: #2a3b4f;
        }

        .schedule-preview .preview-chip i {
            color: #1b66c9;
            margin-right: 6px;
        }

        .schedule-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-top: 8px;
        }

        .assignment-badge {
            background-color: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-top: 5px;
            display: inline-block;
        }

        .assign-form-container {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .assigned-teachers-section {
            margin-top: 30px;
        }

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
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Teachers - <?php echo $_SESSION['department']; ?></h3>
                <div>
                    <span class="badge bg-primary">
                        <i class="fas fa-users me-1"></i>
                        Total: <?php echo $teachers->rowCount(); ?> | 
                        Assigned: <?php echo count($assigned_teachers); ?>
                    </span>
                </div>
            </div>

            <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Assign Teacher form removed (assignments handled via dedicated pages) -->

            

            <!-- All Teachers in Department -->
            <div class="mt-4">
                <h5><i class="fas fa-users me-2"></i>All Teachers in Department</h5>
                <div class="teacher-cards-container">
                    <?php 
                    $teachers->execute(); // Re-execute the query
                    if($teachers->rowCount() > 0): ?>
                        <?php while($teacher_row = $teachers->fetch(PDO::FETCH_ASSOC)): 
                            // Check if teacher is assigned to current evaluator
                            $is_assigned = false;
                            $assignment_info = '';
                            foreach($assigned_teachers as $assigned) {
                                if ($assigned['teacher_id'] == $teacher_row['id']) {
                                    $is_assigned = true;
                                    $assignment_info = $assigned['subject'] ?: 'Grade ' . $assigned['grade_level'];
                                    break;
                                }
                            }
                        ?>
                        <div class="teacher-card">
                            <div class="teacher-photo-section">
                                <?php if(!empty($teacher_row['photo'])): ?>
                                    <img src="../uploads/teachers/<?php echo htmlspecialchars($teacher_row['photo']); ?>" 
                                         alt="<?php echo htmlspecialchars($teacher_row['name']); ?>" 
                                         class="teacher-photo"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="default-photo" style="display: none;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="default-photo">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="teacher-info">
                                <div class="teacher-name"><?php echo htmlspecialchars($teacher_row['name']); ?></div>
                                
                                <div class="status-badge badge bg-<?php echo $teacher_row['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo ucfirst($teacher_row['status']); ?>
                                </div>

                                <!-- Assignment badge removed per request -->

                                <?php if(!empty($teacher_row['evaluation_schedule']) || !empty($teacher_row['evaluation_room'])): ?>
                                <div class="schedule-info">
                                    <?php if(!empty($teacher_row['evaluation_schedule'])): ?>
                                        <?php $scheduleFormatted = date('F d, Y \a\t h:i A', strtotime($teacher_row['evaluation_schedule'])); ?>
                                        <div><i class="fas fa-calendar me-2"></i><?php echo htmlspecialchars($scheduleFormatted); ?></div>
                                    <?php endif; ?>
                                    <?php if(!empty($teacher_row['evaluation_room'])): ?>
                                        <div><i class="fas fa-door-open me-2"></i><?php echo htmlspecialchars($teacher_row['evaluation_room']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <div class="teacher-actions">
                                    <button class="btn btn-sm btn-outline-info" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; line-height: 1.2;" data-bs-toggle="modal" data-bs-target="#scheduleModal" onclick="editSchedule(<?php echo $teacher_row['id']; ?>, '<?php echo htmlspecialchars($teacher_row['evaluation_schedule'] ?? ''); ?>', '<?php echo htmlspecialchars($teacher_row['evaluation_room'] ?? ''); ?>')">
                                        <i class="fas fa-calendar"></i> Schedule
                                    </button>

                                    <?php if(!empty($teacher_row['evaluation_schedule']) || !empty($teacher_row['evaluation_room'])): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark evaluation as done and clear schedule?');">
                                            <input type="hidden" name="action" value="mark_done">
                                            <input type="hidden" name="teacher_id" value="<?php echo $teacher_row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-check"></i> Done
                                            </button>
                                        </form>

                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this schedule?');">
                                            <input type="hidden" name="action" value="cancel_schedule">
                                            <input type="hidden" name="teacher_id" value="<?php echo $teacher_row['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <a href="?action=toggle_status&teacher_id=<?php echo $teacher_row['id']; ?>" class="btn btn-sm btn-outline-<?php echo $teacher_row['status'] == 'active' ? 'warning' : 'success'; ?>" onclick="return confirm('Are you sure?');">
                                        <i class="fas fa-<?php echo $teacher_row['status'] == 'active' ? 'ban' : 'check'; ?>"></i> <?php echo $teacher_row['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <h5>No Teachers Found</h5>
                            <p class="text-muted">No teachers are currently assigned to this department.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <!-- Schedule and Room Modal -->
    <div class="modal fade schedule-modal" id="scheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-check me-2"></i>Set Evaluation Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_schedule">
                        <input type="hidden" name="teacher_id" id="schedule_teacher_id">

                        <div class="schedule-card">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-circle-info text-primary mt-1"></i>
                                <div>
                                    <div class="fw-semibold">This schedule unlocks evaluation access</div>
                                    <div class="text-muted small">Both fields are required so evaluators can plan the observation.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group schedule-field">
                            <label class="form-label">Evaluation Schedule <span class="text-danger">*</span></label>
                            <input type="hidden" id="evaluation_schedule" name="evaluation_schedule" required>
                            <div class="row g-2">
                                <div class="col-7">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        <input type="date" class="form-control" id="evaluation_date" required>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                        <input type="time" class="form-control" id="evaluation_time" step="900" required>
                                    </div>
                                </div>
                            </div>
                            <div class="schedule-help">Pick a date and time for the classroom observation.</div>
                        </div>

                        <div class="form-group schedule-field">
                            <label class="form-label">Classroom/Room <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-location-dot"></i></span>
                                <input type="text" class="form-control" id="evaluation_room" name="evaluation_room" required placeholder="e.g., Room 101, Laboratory B, Building A - Room 303">
                            </div>
                            <div class="schedule-help">Location where the evaluation will take place.</div>
                        </div>

                        <div class="schedule-preview" id="schedulePreview">
                            <span class="preview-chip" id="schedulePreviewTime"><i class="fas fa-calendar"></i>No date selected</span>
                            <span class="preview-chip" id="schedulePreviewRoom"><i class="fas fa-door-open"></i>No room selected</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Schedule & Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function updateSchedulePreview() {
            const dateInput = document.getElementById('evaluation_date');
            const timeInput = document.getElementById('evaluation_time');
            const roomInput = document.getElementById('evaluation_room');
            const previewTime = document.getElementById('schedulePreviewTime');
            const previewRoom = document.getElementById('schedulePreviewRoom');

            if (previewTime) {
                const dateVal = dateInput?.value || '';
                const timeVal = timeInput?.value || '';
                if (dateVal && timeVal) {
                    const date = new Date(`${dateVal}T${timeVal}`);
                    const formatter = new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    });
                    const formatted = isNaN(date.getTime()) ? `${dateVal} ${timeVal}` : formatter.format(date);
                    previewTime.innerHTML = '<i class="fas fa-calendar"></i>' + formatted;
                } else if (dateVal) {
                    const date = new Date(`${dateVal}T00:00`);
                    const formatter = new Intl.DateTimeFormat('en-US', {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric'
                    });
                    const formatted = isNaN(date.getTime()) ? dateVal : formatter.format(date);
                    previewTime.innerHTML = '<i class="fas fa-calendar"></i>' + formatted;
                } else {
                    previewTime.innerHTML = '<i class="fas fa-calendar"></i>No date selected';
                }
            }

            if (previewRoom) {
                const room = roomInput?.value.trim() || '';
                previewRoom.innerHTML = '<i class="fas fa-door-open"></i>' + (room || 'No room selected');
            }
        }

        function editSchedule(teacherId, schedule, room) {
            document.getElementById('schedule_teacher_id').value = teacherId;
            const scheduleInput = document.getElementById('evaluation_schedule');
            const dateInput = document.getElementById('evaluation_date');
            const timeInput = document.getElementById('evaluation_time');
            scheduleInput.value = schedule || '';
            document.getElementById('evaluation_room').value = room;

            if (schedule) {
                const normalized = schedule.replace(' ', 'T');
                const parsed = new Date(normalized);
                if (!isNaN(parsed.getTime())) {
                    dateInput.value = parsed.toISOString().slice(0, 10);
                    timeInput.value = parsed.toTimeString().slice(0, 5);
                } else if (normalized.includes('T')) {
                    const parts = normalized.split('T');
                    dateInput.value = parts[0] || '';
                    timeInput.value = (parts[1] || '').slice(0, 5);
                }
            } else {
                dateInput.value = '';
                timeInput.value = '';
            }
            updateSchedulePreview();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const scheduleInput = document.getElementById('evaluation_schedule');
            const dateInput = document.getElementById('evaluation_date');
            const timeInput = document.getElementById('evaluation_time');
            const roomInput = document.getElementById('evaluation_room');
            if (dateInput) dateInput.addEventListener('input', updateSchedulePreview);
            if (timeInput) timeInput.addEventListener('input', updateSchedulePreview);
            if (roomInput) roomInput.addEventListener('input', updateSchedulePreview);

            const scheduleForm = document.querySelector('#scheduleModal form');
            if (scheduleForm) {
                scheduleForm.addEventListener('submit', () => {
                    const dateVal = dateInput?.value || '';
                    const timeVal = timeInput?.value || '';
                    scheduleInput.value = (dateVal && timeVal) ? `${dateVal} ${timeVal}:00` : '';
                });
            }
        });
    </script>
</body>
</html>