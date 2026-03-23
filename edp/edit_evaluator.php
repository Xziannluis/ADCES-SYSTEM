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
$teacherModel = new Teacher($db);

if (!isset($_GET['id'])) {
    header('Location: users.php');
    exit();
}
$id = $_GET['id'];
$evaluator = $user->getById($id);

// Get evaluator's grade levels (we no longer store subjects here)
$current_grade_levels = [];
if ($evaluator['role'] === 'grade_level_coordinator') {
    $grades_query = "SELECT grade_level FROM evaluator_grade_levels WHERE evaluator_id = :evaluator_id";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':evaluator_id', $id);
    $grades_stmt->execute();
    $current_grade_levels = $grades_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

// Look up evaluator's teacher record for secondary departments
$evaluatorTeacherId = null;
$currentSecondaryDepartments = [];
$teacherIdQuery = $db->prepare("SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1");
$teacherIdQuery->bindParam(':user_id', $id);
$teacherIdQuery->execute();
$evaluatorTeacherId = $teacherIdQuery->fetchColumn();
if ($evaluatorTeacherId) {
    $currentSecondaryDepartments = $teacherModel->getSecondaryDepartments((int)$evaluatorTeacherId);
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedRole = $_POST['role'] ?? '';
    $postedDepartment = $_POST['department'] ?? '';
    if (in_array($postedRole, ['president', 'vice_president'], true)) {
        $postedDepartment = '';
    }

    $data = [
        'name' => $_POST['name'],
        'username' => $_POST['username'],
        'role' => $postedRole,
        'department' => $postedDepartment,
        'password' => $_POST['password'] ?? '',
        'designation' => isset($_POST['designation']) ? $_POST['designation'] : ''
    ];
    
    // Update user
    $result = $user->update($id, $data);

    // Also update username separately if changed
    if (!empty($data['username'])) {
        $updateUsername = $db->prepare("UPDATE users SET username = :username WHERE id = :id");
        $updateUsername->bindParam(':username', $data['username']);
        $updateUsername->bindParam(':id', $id);
        $updateUsername->execute();
    }

    // Update teacher record + secondary departments
    $teacherIdQ = $db->prepare("SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1");
    $teacherIdQ->bindParam(':user_id', $id);
    $teacherIdQ->execute();
    $tId = $teacherIdQ->fetchColumn();
    if ($tId) {
        // Update teacher's name and department too
        $updateTeacher = $db->prepare("UPDATE teachers SET name = :name, department = :department WHERE id = :id");
        $updateTeacher->bindParam(':name', $data['name']);
        $updateTeacher->bindParam(':department', $postedDepartment);
        $updateTeacher->bindParam(':id', $tId);
        $updateTeacher->execute();
    } else {
        // Create teacher record if secondary departments are being assigned
        $secondaryDepts = $_POST['secondary_departments'] ?? [];
        if (!empty($secondaryDepts) && !empty($postedDepartment)) {
            $createTeacher = $db->prepare("INSERT INTO teachers (name, department, status, user_id, created_at) VALUES (:name, :department, 'active', :user_id, NOW())");
            $createTeacher->bindParam(':name', $data['name']);
            $createTeacher->bindParam(':department', $postedDepartment);
            $createTeacher->bindParam(':user_id', $id);
            $createTeacher->execute();
            $tId = $db->lastInsertId();
        }
    }

    if ($tId) {
        $secondaryDepartments = $_POST['secondary_departments'] ?? [];
        if (!is_array($secondaryDepartments)) {
            $secondaryDepartments = [];
        }
        $secondaryDepartments = array_values(array_filter($secondaryDepartments, function ($dept) use ($postedDepartment) {
            return $dept !== '' && $dept !== $postedDepartment;
        }));
        $teacherModel->syncSecondaryDepartments((int)$tId, $secondaryDepartments);
    }

    // Update grade levels for grade level coordinators
    if ($_POST['role'] === 'grade_level_coordinator' && isset($_POST['grade_levels'])) {
        // Delete existing grade levels
        $delete_query = "DELETE FROM evaluator_grade_levels WHERE evaluator_id = :evaluator_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':evaluator_id', $id);
        $delete_stmt->execute();
        
        // Insert new grade levels
        foreach ($_POST['grade_levels'] as $grade_level) {
            $insert_query = "INSERT INTO evaluator_grade_levels (evaluator_id, grade_level, created_at) 
                            VALUES (:evaluator_id, :grade_level, NOW())";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':evaluator_id', $id);
            $insert_stmt->bindParam(':grade_level', $grade_level);
            $insert_stmt->execute();
        }
    }
    
    $_SESSION['success'] = "Evaluator updated successfully!";
    header('Location: users.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Evaluator</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .subjects-container, .grade-levels-container {
            display: none;
            margin-top: 15px;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            background-color: #f8f9fa;
        }
        .subject-checkbox, .grade-checkbox {
            margin-right: 10px;
        }
        .subject-item, .grade-item {
            margin-bottom: 8px;
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
        /* Evaluator name styles */
        .evaluator-name-preview {
            text-transform: uppercase;
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: 0.02em;
        }

        @media (max-width: 767.98px) {
            #editEvaluatorForm .btn {
                width: 100%;
                margin-bottom: 0.5rem;
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
            <form method="POST" id="editEvaluatorForm">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                        <input type="text" class="form-control" id="editNameInput" name="name" value="<?php echo htmlspecialchars($evaluator['name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($evaluator['username']); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role" id="roleSelect" required>
                        <option value="">Select Role</option>
                        <?php $roles = ['president' => 'President', 'vice_president' => 'Vice President', 'dean' => 'Dean', 'principal' => 'Principal', 'subject_coordinator' => 'Subject Coordinator', 'chairperson' => 'Chairperson', 'grade_level_coordinator' => 'Grade Level Coordinator'];
                        foreach($roles as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php if($evaluator['role'] == $key) echo 'selected'; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3" id="departmentFieldWrap">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department" id="departmentSelect">
                        <option value="">Select Department/Category</option>
                        <?php $departments = [
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
                        foreach($departments as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php if($evaluator['department'] == $key) echo 'selected'; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Grade Levels Selection (for Grade Level Coordinators) -->
                <div class="mb-3" id="gradeLevelsContainer" style="display: none;">
                    <label class="form-label">Grade Levels</label>
                    <div class="grade-levels-list" id="gradeLevelsList">
                        <!-- Grade levels will be populated dynamically -->
                    </div>
                </div>

                <!-- Secondary Departments (for evaluators who also teach) -->
                <div class="mb-3" id="secondaryDepartmentsContainer">
                    <label class="form-label">Additional Departments</label>
                    <small class="text-muted d-block mb-2">Select additional departments if this evaluator teaches subjects in other departments</small>
                    <div class="secondary-departments-grid">
                        <?php foreach($allDepartments as $key => $label): ?>
                        <div class="form-check secondary-dept-item" data-dept-value="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>">
                            <input class="form-check-input secondary-dept-cb" type="checkbox" 
                                   name="secondary_departments[]" 
                                   value="<?php echo $key; ?>" 
                                   id="eval_sec_dept_<?php echo $key; ?>"
                                   <?php echo in_array($key, $currentSecondaryDepartments) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="eval_sec_dept_<?php echo $key; ?>"><?php echo $label; ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Password (leave blank to keep current)</label>
                    <input type="password" class="form-control" name="password">
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="users.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
        </div>
    </div>

    <script>

        // Grade levels per department
        const gradeLevelsByDept = {
            'ELEM': ['Nursery', 'Kinder', '1', '2', '3', '4', '5', '6'],
            'JHS': ['7', '8', '9', '10'],
            'SHS': ['11', '12']
        };

        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.getElementById('roleSelect');
            const departmentSelect = document.getElementById('departmentSelect');
            const subjectsContainer = document.getElementById('subjectsContainer');
            const gradeLevelsContainer = document.getElementById('gradeLevelsContainer');
            const subjectsList = document.getElementById('subjectsList');
            const gradeLevelsList = document.getElementById('gradeLevelsList');
            const currentGradeLevels = <?php echo json_encode($current_grade_levels); ?>;

            function toggleSpecializations() {
                const role = roleSelect.value;
                const departmentFieldWrap = document.getElementById('departmentFieldWrap');
                const secondaryContainer = document.getElementById('secondaryDepartmentsContainer');
                
                if (role === 'president' || role === 'vice_president') {
                    departmentFieldWrap.style.display = 'none';
                    departmentSelect.value = '';
                    if (secondaryContainer) secondaryContainer.style.display = 'none';
                } else {
                    departmentFieldWrap.style.display = 'block';
                    if (secondaryContainer) secondaryContainer.style.display = 'block';
                }

                // Sync secondary department options (hide primary)
                syncSecondaryDepts();

                // Hide grade levels container first
                gradeLevelsContainer.style.display = 'none';
                if (role === 'grade_level_coordinator') {
                    gradeLevelsContainer.style.display = 'block';
                    populateGradeLevels();
                }
            }

            function syncSecondaryDepts() {
                const primary = departmentSelect.value;
                const items = document.querySelectorAll('.secondary-dept-item');
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

            function populateGradeLevels() {
                gradeLevelsList.innerHTML = '';
                const department = departmentSelect.value;
                const grades = gradeLevelsByDept[department] || [];
                
                grades.forEach(grade => {
                    const isChecked = currentGradeLevels.includes(grade);
                    const gradeDiv = document.createElement('div');
                    gradeDiv.className = 'form-check grade-item';
                    const label = isNaN(grade) ? grade : `Grade ${grade}`;
                    gradeDiv.innerHTML = `
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_levels[]" value="${grade}" id="grade_${grade}" ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label" for="grade_${grade}">
                            ${label}
                        </label>
                    `;
                    gradeLevelsList.appendChild(gradeDiv);
                });
            }

            roleSelect.addEventListener('change', toggleSpecializations);
            departmentSelect.addEventListener('change', function() {
                toggleSpecializations();
            });
            
            // Initialize on page load
            toggleSpecializations();
        });
    </script>
</body>
</html>