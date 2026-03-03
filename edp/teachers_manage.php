<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}
require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/User.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);
$user = new User($db);

// Handle form submissions
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch($_POST['action']) {
        case 'deactivate':
            if($teacher->updateStatus($_POST['teacher_id'], 'inactive')) {
                $_SESSION['success'] = "Teacher account deactivated successfully.";
            } else {
                $_SESSION['error'] = "Failed to deactivate teacher account.";
            }
            break;
            
        case 'activate':
            if($teacher->updateStatus($_POST['teacher_id'], 'active')) {
                $_SESSION['success'] = "Teacher account activated successfully.";
            } else {
                $_SESSION['error'] = "Failed to activate teacher account.";
            }
            break;
            
        case 'create_account':
            // Generate username from name and teacher ID
            $teacher_id = $_POST['teacher_id'];
            $teacher_data = $teacher->getById($teacher_id);
            
            if(!$teacher_data) {
                $_SESSION['error'] = "Teacher not found.";
                break;
            }
            
            // Check if user already exists for this teacher
            $check_query = "SELECT id FROM teachers WHERE id = :teacher_id AND user_id IS NOT NULL";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':teacher_id', $teacher_id);
            $check_stmt->execute();
            
            if($check_stmt->rowCount() > 0) {
                $_SESSION['error'] = "This teacher already has an account.";
                break;
            }
            
            // Generate username from teacher name
            $name_parts = explode(' ', $teacher_data['name']);
            $base_username = strtolower(substr($name_parts[0], 0, 1) . end($name_parts));
            $username = $base_username . $teacher_id;
            
            // Generate random password
            $password = 'Teacher@' . substr(str_shuffle('0123456789'), 0, 4) . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2);
            
            // Check if username already exists
            if($user->usernameExists($username)) {
                $_SESSION['error'] = "Username $username already exists. Please try again.";
                break;
            }
            
            // Create user account
            $user_data = [
                'username' => $username,
                'password' => $password,
                'name' => $teacher_data['name'],
                'role' => 'teacher',
                'department' => $teacher_data['department']
            ];
            
            $result = $user->create($user_data);
            
            if($result) {
                // Get the newly created user ID
                $query = "SELECT id FROM users WHERE username = :username";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $username);
                $stmt->execute();
                $new_user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Update teacher with user_id
                $update_query = "UPDATE teachers SET user_id = :user_id WHERE id = :teacher_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':user_id', $new_user['id']);
                $update_stmt->bindParam(':teacher_id', $teacher_id);
                $update_stmt->execute();
                
                $_SESSION['success'] = "Teacher account created! Username: <strong>$username</strong> | Password: <strong>$password</strong>";
            } else {
                $_SESSION['error'] = "Failed to create teacher account.";
            }
            break;
    }
    header('Location: teachers_manage.php');
    exit();
}

// Get teachers without accounts and with accounts
$all_teachers = $teacher->getAllTeachers('active');
$teachers_with_accounts = [];
$teachers_without_accounts = [];

while($row = $all_teachers->fetch(PDO::FETCH_ASSOC)) {
    if($row['user_id']) {
        // Get user info
        $user_query = "SELECT * FROM users WHERE id = :user_id AND role = 'teacher'";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':user_id', $row['user_id']);
        $user_stmt->execute();
        
        if($user_stmt->rowCount() > 0) {
            $user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $row['username'] = $user_info['username'];
            $row['user_status'] = $user_info['status'];
            $teachers_with_accounts[] = $row;
        }
    } else {
        $teachers_without_accounts[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teacher Accounts - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }
        
        .teacher-card.no-account {
            border-left-color: #e74c3c;
        }
        
        .teacher-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .teacher-details h5 {
            margin: 0;
            color: #2c3e50;
        }
        
        .teacher-details p {
            margin: 5px 0 0 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .account-status {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .account-status.active {
            background: #d4edda;
            color: #155724;
        }
        
        .account-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .account-status.no-account {
            background: #fff3cd;
            color: #856404;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Manage Teacher Accounts</h3>
                <span class="badge bg-info p-2">
                    <i class="fas fa-users me-2"></i>
                    With Accounts: <?php echo count($teachers_with_accounts); ?> | 
                    Need Accounts: <?php echo count($teachers_without_accounts); ?>
                </span>
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

            <!-- Tabs -->
            <div class="nav nav-tabs mb-4" role="tablist">
                <button class="nav-link active" id="with-account-tab" data-bs-toggle="tab" data-bs-target="#with-account" type="button" role="tab">
                    <i class="fas fa-check-circle me-2"></i>Teachers with Accounts (<?php echo count($teachers_with_accounts); ?>)
                </button>
                <button class="nav-link" id="without-account-tab" data-bs-toggle="tab" data-bs-target="#without-account" type="button" role="tab">
                    <i class="fas fa-user-plus me-2"></i>Create Account (<?php echo count($teachers_without_accounts); ?>)
                </button>
            </div>

            <!-- Tab 1: Teachers with Accounts -->
            <div id="with-account" class="tab-content active">
                <?php if(count($teachers_with_accounts) > 0): ?>
                    <?php foreach($teachers_with_accounts as $teach): ?>
                    <div class="teacher-card">
                        <div class="teacher-info">
                            <div class="teacher-details">
                                <h5><?php echo htmlspecialchars($teach['name']); ?></h5>
                                <p>
                                    <i class="fas fa-building me-1"></i><?php echo htmlspecialchars($teach['department']); ?> | 
                                    <i class="fas fa-user me-1"></i><code><?php echo htmlspecialchars($teach['username']); ?></code>
                                </p>
                            </div>
                            <div>
                                <span class="account-status <?php echo $teach['user_status']; ?>">
                                    <i class="fas fa-circle me-1"></i><?php echo ucfirst($teach['user_status']); ?>
                                </span>
                                <form method="POST" style="display: inline-block; margin-left: 10px;">
                                    <input type="hidden" name="teacher_id" value="<?php echo $teach['id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $teach['user_status'] == 'active' ? 'deactivate' : 'activate'; ?>">
                                    <button type="submit" class="btn btn-sm btn-<?php echo $teach['user_status'] == 'active' ? 'warning' : 'success'; ?>">
                                        <i class="fas fa-<?php echo $teach['user_status'] == 'active' ? 'user-slash' : 'user-check'; ?> me-1"></i>
                                        <?php echo $teach['user_status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No teachers with accounts yet. Create some in the "Create Account" tab.
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab 2: Create Accounts for Teachers Without -->
            <div id="without-account" class="tab-content">
                <?php if(count($teachers_without_accounts) > 0): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Click <strong>"Create Account"</strong> button to generate login credentials for each teacher. 
                        Credentials will be displayed after creation.
                    </div>
                    
                    <?php foreach($teachers_without_accounts as $teach): ?>
                    <div class="teacher-card no-account">
                        <div class="teacher-info">
                            <div class="teacher-details">
                                <h5><?php echo htmlspecialchars($teach['name']); ?></h5>
                                <p>
                                    <i class="fas fa-building me-1"></i><?php echo htmlspecialchars($teach['department']); ?> | 
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-exclamation-triangle me-1"></i>No Account
                                    </span>
                                </p>
                            </div>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="teacher_id" value="<?php echo $teach['id']; ?>">
                                <input type="hidden" name="action" value="create_account">
                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Create account for <?php echo htmlspecialchars($teach['name']); ?>?');">
                                    <i class="fas fa-user-plus me-1"></i>Create Account
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Perfect!</strong> All teachers have accounts. 
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
