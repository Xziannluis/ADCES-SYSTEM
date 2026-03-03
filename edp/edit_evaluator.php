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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => $_POST['name'],
        'username' => $_POST['username'],
        'role' => $_POST['role'],
        'department' => $_POST['department'],
        'password' => $_POST['password'] ?? '',
        'designation' => isset($_POST['designation']) ? $_POST['designation'] : ''
    ];
    
    // Update user
    $user->update($id, $data);

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
        /* Evaluator name/designation preview styles */
        .evaluator-name-preview {
            text-transform: uppercase;
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: 0.02em;
        }
        .evaluator-designation-preview {
            text-transform: uppercase;
            font-size: 0.85rem;
            color: #6c757d;
            letter-spacing: 0.06em;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <h3>Edit Evaluator</h3>
            <form method="POST" id="editEvaluatorForm">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                        <input type="text" class="form-control" id="editNameInput" name="name" value="<?php echo htmlspecialchars($evaluator['name']); ?>" required>
                        <input type="text" class="form-control mt-2" name="designation" id="editDesignationInput" value="<?php echo htmlspecialchars($evaluator['designation'] ?? ''); ?>" placeholder="e.g. IT Program Head" style="display:block; max-width:420px;">
                        <div id="editEvaluatorPreview" class="mt-3">
                            <div id="editEvaluatorNamePreview" class="evaluator-name-preview"><?php echo strtoupper(htmlspecialchars($evaluator['name'])); ?></div>
                            <?php if (!empty($evaluator['designation'])): ?>
                                <div id="editEvaluatorDesignation" class="evaluator-designation-preview"><?php echo strtoupper(htmlspecialchars($evaluator['designation'])); ?></div>
                            <?php else: ?>
                                <div id="editEvaluatorDesignation" class="evaluator-designation-preview" style="display:none;"></div>
                            <?php endif; ?>
                        </div>
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
                <div class="mb-3">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department" id="departmentSelect">
                        <option value="">Select Department/Category</option>
                        <?php $departments = [
                            'CTE' => 'College of Teacher Education',
                            'CAS' => 'College of Arts and Sciences',
                            'CCJE' => 'College of Criminal Justice Education',
                            'CBM' => 'College of Business Management',
                            'CCIS' => 'College of Computing and Information Sciences',
                            'CTHM' => 'College of Tourism and Hospitality Management',
                            'BASIC ED' => 'BASIC ED (Nursery, Kindergarten, Elementary, Junior High School)',
                            'SHS' => 'Senior High School (SHS)'
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
                
                <div class="mb-3">
                    <label class="form-label">Password (leave blank to keep current)</label>
                    <input type="password" class="form-control" name="password">
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="users.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>

    <script>

        // Grade levels
        const gradeLevels = ['7', '8', '9', '10', '11', '12'];

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
                const department = departmentSelect.value;
                
                // Hide grade levels container first
                gradeLevelsContainer.style.display = 'none';
                if (role === 'grade_level_coordinator') {
                    gradeLevelsContainer.style.display = 'block';
                    populateGradeLevels();
                }
            }

            function populateGradeLevels() {
                gradeLevelsList.innerHTML = '';
                
                gradeLevels.forEach(grade => {
                    const isChecked = currentGradeLevels.includes(grade);
                    const gradeDiv = document.createElement('div');
                    gradeDiv.className = 'form-check grade-item';
                    gradeDiv.innerHTML = `
                        <input class="form-check-input grade-checkbox" type="checkbox" name="grade_levels[]" value="${grade}" id="grade_${grade}" ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label" for="grade_${grade}">
                            Grade ${grade}
                        </label>
                    `;
                    gradeLevelsList.appendChild(gradeDiv);
                });
            }

            roleSelect.addEventListener('change', toggleSpecializations);
            departmentSelect.addEventListener('change', toggleSpecializations);
            
            // Initialize on page load
            toggleSpecializations();
            // Designation map and preview for edit form
            const editDesignationMap = {
                'CCIS': 'IT Program Head',
                'CAS': 'AB Program Head',
                'CTE': 'Teacher Education Program Head',
                'CBM': 'Business Program Head',
                'CCJE': 'Criminal Justice Program Head',
                'CTHM': 'Tourism & Hospitality Program Head',
                'ELEM': 'Elementary Program Head',
                'JHS': 'Junior High Program Head',
                'SHS': 'Senior High Program Head',
                'BASIC ED': 'Basic Education Program Head',
                'BSED': 'BSED Program Head'
            };

            const editDesignationEl = document.getElementById('editEvaluatorDesignation');
            const editDesignationInput = document.getElementById('editDesignationInput');
            const editNameInput = document.getElementById('editNameInput');
            const editNamePreview = document.getElementById('editEvaluatorNamePreview');
            const editDesignationPreview = document.getElementById('editEvaluatorDesignation');
            function updateEditDesignation() {
                const role = document.getElementById('roleSelect').value;
                const dept = document.getElementById('departmentSelect').value;
                if ((role === 'subject_coordinator' || role === 'chairperson' || role === 'grade_level_coordinator') && dept) {
                    const label = editDesignationMap[dept] || (dept.toUpperCase() + ' Program Head');
                    editDesignationEl.textContent = label;
                    editDesignationEl.style.display = 'block';
                    // If the designation input is empty, populate it with the suggested label
                    if (editDesignationInput && !editDesignationInput.value) {
                        editDesignationInput.value = label;
                    }
                    // show preview
                    if (editDesignationPreview) {
                        editDesignationPreview.textContent = (editDesignationInput && editDesignationInput.value) ? editDesignationInput.value.toUpperCase() : label.toUpperCase();
                        editDesignationPreview.style.display = 'block';
                    }
                } else {
                    editDesignationEl.style.display = 'none';
                    if (editDesignationPreview) editDesignationPreview.style.display = 'none';
                }
            }

            // Live preview for name and designation
            function updateNamePreview() {
                if (editNamePreview && editNameInput) {
                    editNamePreview.textContent = editNameInput.value ? editNameInput.value.toUpperCase() : '';
                }
            }

            if (editNameInput) editNameInput.addEventListener('input', updateNamePreview);
            if (editDesignationInput) editDesignationInput.addEventListener('input', function() {
                if (editDesignationPreview) {
                    editDesignationPreview.textContent = editDesignationInput.value ? editDesignationInput.value.toUpperCase() : '';
                    editDesignationPreview.style.display = editDesignationInput.value ? 'block' : 'none';
                }
            });

            document.getElementById('roleSelect').addEventListener('change', updateEditDesignation);
            document.getElementById('departmentSelect').addEventListener('change', updateEditDesignation);
            // Run once to initialize based on current values
            updateEditDesignation();
        });
    </script>
</body>
</html>