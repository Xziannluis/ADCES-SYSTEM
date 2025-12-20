<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}
require_once '../config/database.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

// Define departments array
$departments = [
    'CCIS' => '(CCIS) College of Computing and Information Sciences',
    'CTE' => '(CTE) College of Teacher Education',
    'CAS' => '(CAS) College of Arts and Sciences',
    'CCJE' => '(CCJE) College of Criminal Justice Education',
    'CBM' => '(CBM) College of Business Management',
    'CTHM' => '(CTHM) College of Tourism and Hospitality Management',
    'ELEM' => '(ELEM) Elementary School)',
    'JHS' => '(JHS) Junior High School)',
    'SHS' => '(SHS) Senior High School'
];

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Teacher ID is required.";
    header('Location: teachers.php');
    exit();
}

$id = $_GET['id'];
$teacherData = $teacher->getById($id);

if (!$teacherData) {
    $_SESSION['error'] = "Teacher not found.";
    header('Location: teachers.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $department = $_POST['department'];
    
    if (empty($name) || empty($department)) {
        $_SESSION['error'] = "Please fill in all required fields.";
    } else {
        $data = [
            'name' => $name,
            'department' => $department
        ];
        
        if ($teacher->update($id, $data)) {
            $_SESSION['success'] = "Teacher updated successfully!";
            header('Location: teachers.php');
            exit();
        } else {
            $_SESSION['error'] = "Failed to update teacher. Please try again.";
        }
    }
    
    // Reload teacher data to show updated values in form
    $teacherData = $teacher->getById($id);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Teacher - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .form-required::after {
            content: " *";
            color: #dc3545;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-container {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .current-info {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Edit Teacher</h3>
                <a href="teachers.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Teachers
                </a>
            </div>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-edit me-2"></i>Edit Teacher Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="editTeacherForm" novalidate>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Teacher Name</label>
                                            <input type="text" class="form-control" name="name" 
                                                   value="<?php echo htmlspecialchars($teacherData['name']); ?>" 
                                                   required placeholder="Enter teacher's full name">
                                            <div class="invalid-feedback">Please enter the teacher's name.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label form-required">Department</label>
                                            <select class="form-select" name="department" required>
                                                <option value="">Select Department</option>
                                                <?php foreach($departments as $key => $label): ?>
                                                    <option value="<?php echo $key; ?>" 
                                                        <?php echo ($teacherData['department'] == $key) ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="invalid-feedback">Please select a department.</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-12">
                                        <div class="btn-container">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-2"></i>Save Changes
                                            </button>
                                            <a href="teachers.php" class="btn btn-secondary">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </a>
                                            <?php if($teacherData['status'] == 'active'): ?>
                                                <form method="POST" action="teachers.php" style="display: inline;">
                                                    <input type="hidden" name="teacher_id" value="<?php echo $teacherData['id']; ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn btn-warning" 
                                                            onclick="return confirm('Are you sure you want to deactivate this teacher?')">
                                                        <i class="fas fa-user-slash me-2"></i>Deactivate Teacher
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="teachers.php" style="display: inline;">
                                                    <input type="hidden" name="teacher_id" value="<?php echo $teacherData['id']; ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-success" 
                                                            onclick="return confirm('Are you sure you want to activate this teacher?')">
                                                        <i class="fas fa-user-check me-2"></i>Activate Teacher
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Teacher Information Card -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle me-2"></i>Current Information
                            </h5>
                        </div>
                        <div class="card-body current-info">
                            <div class="mb-3">
                                <strong>Teacher ID:</strong><br>
                                <span class="text-muted"><?php echo htmlspecialchars($teacherData['id']); ?></span>
                            </div>
                            <div class="mb-3">
                                <strong>Name:</strong><br>
                                <span class="text-muted"><?php echo htmlspecialchars($teacherData['name']); ?></span>
                            </div>
                            <div class="mb-3">
                                <strong>Department:</strong><br>
                                <span class="text-muted"><?php echo htmlspecialchars($teacherData['department']); ?></span>
                            </div>
                            <div class="mb-3">
                                <strong>Status:</strong><br>
                                <span class="badge bg-<?php echo $teacherData['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo ucfirst($teacherData['status']); ?>
                                </span>
                            </div>
                            <?php if(!empty($teacherData['created_at'])): ?>
                            <div class="mb-3">
                                <strong>Created:</strong><br>
                                <span class="text-muted"><?php echo date('M j, Y', strtotime($teacherData['created_at'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($teacherData['updated_at'])): ?>
                            <div class="mb-3">
                                <strong>Last Updated:</strong><br>
                                <span class="text-muted"><?php echo date('M j, Y', strtotime($teacherData['updated_at'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Form validation
        document.getElementById('editTeacherForm').addEventListener('submit', function(e) {
            const form = this;
            
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                return;
            }

            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            submitBtn.disabled = true;

            // Form will submit normally
        });
    </script>
</body>
</html>