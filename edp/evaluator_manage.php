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
$hasProgramColumn = false;
try {
    $programColumnStmt = $db->query("SHOW COLUMNS FROM users LIKE 'program'");
    $hasProgramColumn = $programColumnStmt && $programColumnStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasProgramColumn = false;
}
$roles = ['president', 'vice_president', 'dean', 'principal', 'subject_coordinator', 'chairperson'];
$evaluators = [];
foreach ($roles as $role) {
    $evaluators[$role] = $user->getUsersByRole($role, 'active');
}
// Handle deactivate action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['action']) && $_POST['action'] === 'deactivate') {
    $user->updateStatus($_POST['user_id'], 'inactive');
    header('Location: users.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Evaluators</title>
    <?php include '../includes/header.php'; ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-header">
                <h3 class="mb-0">Manage Evaluators (Edit/Deactivate)</h3>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive table-min-760">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th style="vertical-align: middle;">
                                        <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                            Actions
                                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEvaluatorModal" style="font-size:0.95em; padding: 0.25rem 0.75rem; line-height: 1;">
                                                <i class="fas fa-plus"></i> Add
                                            </button>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
    <!-- Add Evaluator Modal -->
    <div class="modal fade" id="addEvaluatorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Evaluator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="users.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role" id="roleSelect" required>
                                <option value="">Select Role</option>
                                <option value="president">President</option>
                                <option value="vice_president">Vice President</option>
                                <option value="dean">Dean</option>
                                <option value="principal">Principal</option>
                                <option value="subject_coordinator">Subject Coordinator</option>
                                <option value="chairperson">Chairperson</option>
                            </select>
                        </div>
                        <div class="mb-3" id="programDiv" style="display:none;">
                            <label class="form-label">Program</label>
                            <select class="form-select" name="program" id="programSelect">
                                <option value="">Select Program</option>
                                <option value="BASIC ED">Basic Ed</option>
                                <option value="COLLEGE">College</option>
                            </select>
                            <?php if (!$hasProgramColumn): ?>
                                <div class="form-text text-warning">The `users.program` column was not found, so program selection will only drive the department choices until that column is added.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3" id="departmentDiv" style="display:none;">
                            <label class="form-label">Department/Category</label>
                            <select class="form-select" name="department" id="departmentSelect">
                                <option value="">Select Department/Category</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    // Show/hide department/category based on role
    document.addEventListener('DOMContentLoaded', function() {
        var roleSelect = document.getElementById('roleSelect');
        var programDiv = document.getElementById('programDiv');
        var programSelect = document.getElementById('programSelect');
        var departmentDiv = document.getElementById('departmentDiv');
        var departmentSelect = document.getElementById('departmentSelect');
        var basicEdDepartments = [
            { value: 'ELEMENTARY DEPARTMENT', label: 'Elementary Department' },
            { value: 'HIGH SCHOOL DEPARTMENT', label: 'High School Department' },
            { value: 'JUNIOR HIGH SCHOOL DEPARTMENT', label: 'Junior High School Department' }
        ];
        var collegeDepartments = [
            { value: 'CAS', label: 'CAS' },
            { value: 'CCJE', label: 'CCJE' },
            { value: 'CCIS', label: 'CCIS' },
            { value: 'CBM', label: 'CBM' },
            { value: 'CTHM', label: 'CTHM' },
            { value: 'CTE', label: 'CTE' }
        ];

        function renderDepartmentOptions(program) {
            var items = [];
            if (program === 'BASIC ED') {
                items = basicEdDepartments;
            } else if (program === 'COLLEGE') {
                items = collegeDepartments;
            }

            departmentSelect.innerHTML = '<option value="">Select Department/Category</option>';
            items.forEach(function(item) {
                var option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                departmentSelect.appendChild(option);
            });
        }

        function toggleDepartment() {
            var role = roleSelect.value;
            if(role === 'dean' || role === 'principal' || role === 'subject_coordinator' || role === 'chairperson') {
                programDiv.style.display = '';
                programSelect.required = true;
                departmentDiv.style.display = '';
                departmentSelect.required = !!programSelect.value;
                renderDepartmentOptions(programSelect.value);
            } else {
                programDiv.style.display = 'none';
                programSelect.required = false;
                programSelect.value = '';
                departmentDiv.style.display = 'none';
                departmentSelect.required = false;
                departmentSelect.value = '';
                departmentSelect.innerHTML = '<option value="">Select Department/Category</option>';
            }
        }
        roleSelect.addEventListener('change', toggleDepartment);
        programSelect.addEventListener('change', function() {
            renderDepartmentOptions(programSelect.value);
            departmentSelect.required = !!programSelect.value;
            departmentSelect.value = '';
        });
        toggleDepartment();
    });
    </script>
                            <tbody>
                                <?php 
                                $counter = 1;
                                foreach ($roles as $role) {
                                    while($row = $evaluators[$role]->fetch(PDO::FETCH_ASSOC)):
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>
                                        <span class="badge bg-success">Active</span>
                                    </td>
                                    <td>
                                        <a href="edit_evaluator.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">Edit</a>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <button type="submit" class="btn btn-sm btn-warning">Deactivate</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>
