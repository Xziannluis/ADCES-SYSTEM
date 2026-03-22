<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/program_assignments.php';
require_once '../includes/photo_helper.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

$success_message = '';
$error_message = '';

// Handle reactivate action — Dean/Principal only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reactivate') {
    if (!in_array($_SESSION['role'], ['dean', 'principal'])) {
        $_SESSION['error'] = "Only the Dean or Principal can manage deactivated teachers.";
        header("Location: ../evaluators/dashboard.php");
        exit();
    }
    $teacher_id = $_POST['teacher_id'] ?? '';
    if (!empty($teacher_id)) {
        if ($teacher->updateStatus($teacher_id, 'active')) {
            $success_message = "Teacher reactivated successfully!";
        } else {
            $error_message = "Failed to reactivate teacher.";
        }
    }
    header("Location: deactivated_teachers.php");
    exit();
}

// Get inactive teachers for current department
if (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    $programs = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $_SESSION['department'] ?? null);
    $query = "SELECT t.* FROM teachers t JOIN teacher_assignments ta ON ta.teacher_id = t.id WHERE ta.evaluator_id = :evaluator_id AND t.status = 'inactive'";
    if (!empty($programs)) {
        $placeholders = [];
        foreach ($programs as $idx => $dept) {
            $placeholders[] = ':program_' . $idx;
        }
        $query .= " AND t.department IN (" . implode(',', $placeholders) . ")";
    }
    $query .= " ORDER BY t.name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    if (!empty($programs)) {
        foreach ($programs as $idx => $dept) {
            $stmt->bindValue(':program_' . $idx, $dept);
        }
    }
    $stmt->execute();
    $inactive_teachers = $stmt;
} else {
    // Dean/principal: get all inactive teachers in their department
    $query = "SELECT DISTINCT t.* FROM teachers t LEFT JOIN teacher_departments td ON td.teacher_id = t.id WHERE (t.department = :department OR td.department = :department2) AND t.status = 'inactive' ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $dept = $_SESSION['department'];
    $stmt->bindParam(':department', $dept);
    $stmt->bindParam(':department2', $dept);
    $stmt->execute();
    $inactive_teachers = $stmt;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deactivated Teachers - <?php echo htmlspecialchars($_SESSION['department']); ?></title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 280px));
            gap: 1.25rem;
        }
        .teacher-photo-section {
            position: relative;
            height: 180px;
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
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
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }
        .teacher-actions {
            justify-content: center;
            margin-top: 15px;
        }
        .teacher-actions .btn {
            min-width: 80px;
            font-size: 0.75rem;
            padding: 5px 10px;
        }
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
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

            <?php if(!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0"><i class="fas fa-user-slash me-2"></i>Deactivated Teachers</h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="btn-group" role="group" aria-label="Semester filter">
                        <button type="button" class="btn btn-sm btn-outline-secondary active" onclick="filterSem('all', this)">All</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="filterSem('1st', this)">1st Semester</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="filterSem('2nd', this)">2nd Semester</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="filterSem('Both', this)">Both</button>
                    </div>
                    <a href="teachers.php" class="btn btn-sm btn-outline-dark"><i class="fas fa-arrow-left me-1"></i> Back to Teachers</a>
                </div>
            </div>

            <div class="teacher-cards-container">
                <?php if($inactive_teachers->rowCount() > 0): ?>
                    <?php while($row = $inactive_teachers->fetch(PDO::FETCH_ASSOC)): ?>
                    <div class="teacher-card" data-semester="<?php echo htmlspecialchars($row['teaching_semester'] ?? ''); ?>">
                        <div class="teacher-photo-section">
                            <?php
                                $teacherPhotoUrl = getPhotoUrl('teacher', $row['id'], $row['photo_path'] ?? '');
                            ?>
                            <?php if($teacherPhotoUrl): ?>
                                <img src="<?php echo htmlspecialchars($teacherPhotoUrl); ?>" 
                                     alt="<?php echo htmlspecialchars($row['name']); ?>" 
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
                            <div class="teacher-name"><?php echo htmlspecialchars($row['name']); ?></div>
                            <div class="status-badge badge bg-secondary">Inactive</div>
                            <?php $tSem = $row['teaching_semester'] ?? ''; if ($tSem): ?>
                            <div style="margin-top:6px; font-size:0.78rem; color:#6c757d;">
                                <i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars($tSem === 'Both' ? 'Both Semesters' : $tSem . ' Semester Only'); ?>
                            </div>
                            <?php endif; ?>
                            <div class="teacher-actions">
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="teacher_id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="hidden" name="action" value="reactivate">
                                    <button type="submit" class="btn btn-sm btn-outline-dark" onclick="return confirm('Reactivate this teacher?');">
                                        <i class="fas fa-check"></i> Reactivate
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Deactivated Teachers</h5>
                        <p class="text-muted">All teachers in your department are currently active.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    function filterSem(semester, btn) {
        document.querySelectorAll('.btn-group [onclick^="filterSem"]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const cards = document.querySelectorAll('.teacher-card[data-semester]');
        cards.forEach(card => {
            const s = card.getAttribute('data-semester') || '';
            if (semester === 'all') {
                card.style.display = '';
            } else {
                card.style.display = (s === semester || s === 'Both') ? '' : 'none';
            }
        });
    }
    </script>
</body>
</html>
