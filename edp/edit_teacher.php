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

// All departments for secondary selection
$allDepartments = [
    'ELEM' => '(ELEM) Elementary Department',
    'JHS' => '(JHS) Junior High School Department',
    'SHS' => '(SHS) Senior High School Department',
    'CCIS' => '(CCIS) College of Computing and Information Sciences',
    'CAS' => '(CAS) College of Arts and Sciences',
    'CTEAS' => '(CTEAS) College of Teacher Education and Arts and Sciences',
    'CBM' => '(CBM) College of Business Management',
    'CTHM' => '(CTHM) College of Tourism and Hospitality Management',
    'CCJE' => '(CCJE) College of Criminal Justice Education'
];

// Define departments array
$departments = [
    'ELEM' => '(ELEM) Elementary Department',
    'JHS' => '(JHS) Junior High School Department',
    'SHS' => '(SHS) Senior High School Department',
    'CCIS' => '(CCIS) College of Computing and Information Sciences',
    'CAS' => '(CAS) College of Arts and Sciences',
    'CTEAS' => '(CTEAS) College of Teacher Education and Arts and Sciences',
    'CBM' => '(CBM) College of Business Management',
    'CTHM' => '(CTHM) College of Tourism and Hospitality Management',
    'CCJE' => '(CCJE) College of Criminal Justice Education'
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

// Load current secondary departments
$currentSecondaryDepartments = $teacher->getSecondaryDepartments($id);

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
            // Sync secondary departments
            $secondaryDepartments = $_POST['secondary_departments'] ?? [];
            if (!is_array($secondaryDepartments)) {
                $secondaryDepartments = [];
            }
            $secondaryDepartments = array_values(array_filter($secondaryDepartments, function ($dept) use ($department) {
                return $dept !== '' && $dept !== $department;
            }));
            $teacher->syncSecondaryDepartments((int)$id, $secondaryDepartments);

            // Update password in users table if provided
            $newPassword = trim($_POST['password'] ?? '');
            if (!empty($newPassword) && !empty($teacherData['user_id'])) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $pwStmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
                $pwStmt->bindParam(':password', $hashedPassword);
                $pwStmt->bindParam(':id', $teacherData['user_id']);
                $pwStmt->execute();
            }

            // Also sync name to users table
            if (!empty($teacherData['user_id'])) {
                $syncName = $db->prepare("UPDATE users SET name = :name, department = :department WHERE id = :id");
                $syncName->bindParam(':name', $name);
                $syncName->bindParam(':department', $department);
                $syncName->bindParam(':id', $teacherData['user_id']);
                $syncName->execute();
            }

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
        .secondary-departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 6px;
            padding: 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: #f8f9fa;
        }
        .secondary-dept-item.hidden-dept {
            display: none;
        }

        @media (max-width: 991.98px) {
            .d-flex.justify-content-between.align-items-center.mb-4 {
                flex-direction: column;
                align-items: stretch !important;
                gap: 0.75rem;
            }
        }

        @media (max-width: 767.98px) {
            .btn-container > * {
                width: 100%;
            }

            .btn-container .btn,
            .btn-container a {
                width: 100%;
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

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
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
                                            <select class="form-select" name="department" id="primaryDepartment" required>
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

                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label class="form-label">Additional Departments</label>
                                            <small class="text-muted d-block mb-2">Select additional departments if this teacher has subjects in other departments</small>
                                            <div class="secondary-departments-grid">
                                                <?php foreach($allDepartments as $key => $label): ?>
                                                <div class="form-check secondary-dept-item" data-dept-value="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>">
                                                    <input class="form-check-input secondary-dept-cb" type="checkbox" 
                                                           name="secondary_departments[]" 
                                                           value="<?php echo $key; ?>" 
                                                           id="sec_dept_<?php echo $key; ?>"
                                                           <?php echo in_array($key, $currentSecondaryDepartments) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="sec_dept_<?php echo $key; ?>"><?php echo $label; ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Password (leave blank to keep current)</label>
                                            <input type="password" class="form-control" name="password" placeholder="Enter new password">
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Secondary departments logic
        (function() {
            const primarySelect = document.getElementById('primaryDepartment');
            const checkboxes = document.querySelectorAll('.secondary-dept-cb');
            const items = document.querySelectorAll('.secondary-dept-item');

            function syncSecondaryDepts() {
                const primary = primarySelect.value;
                items.forEach(item => {
                    const deptVal = item.getAttribute('data-dept-value');
                    const cb = item.querySelector('.secondary-dept-cb');
                    if (deptVal === primary) {
                        item.classList.add('hidden-dept');
                        if (cb) { cb.checked = false; cb.disabled = true; }
                    } else {
                        item.classList.remove('hidden-dept');
                        if (cb) cb.disabled = false;
                    }
                });
            }

            primarySelect.addEventListener('change', syncSecondaryDepts);
            syncSecondaryDepts();
        })();

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