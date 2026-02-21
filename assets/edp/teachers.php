<?php
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
$selected_department = isset($_GET['department']) ? $_GET['department'] : '';
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

// Handle teacher creation
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if($_POST['action'] == 'create') {
        $name = trim($_POST['name']);
        $department = $_POST['department'];
        
        if(empty($name) || empty($department)) {
            $_SESSION['error'] = "Please fill in all required fields.";
        } else {
            $data = [
                'name' => $name,
                'department' => $department,
                'status' => 'active'
            ];
            
            if($teacher->create($data)) {
                $_SESSION['success'] = "Teacher created successfully!";
            } else {
                $_SESSION['error'] = "Failed to create teacher. Please try again.";
            }
        }
        header("Location: teachers.php");
        exit();
    }
    
    // Handle teacher deactivation
    if($_POST['action'] == 'deactivate') {
        if($teacher->updateStatus($_POST['teacher_id'], 'inactive')) {
            $_SESSION['success'] = "Teacher deactivated successfully.";
        } else {
            $_SESSION['error'] = "Failed to deactivate teacher.";
        }
        header("Location: teachers.php");
        exit();
    } elseif($_POST['action'] == 'activate') {
        if($teacher->updateStatus($_POST['teacher_id'], 'active')) {
            $_SESSION['success'] = "Teacher activated successfully.";
        } else {
            $_SESSION['error'] = "Failed to activate teacher.";
        }
        header("Location: teachers.php");
        exit();
    }
}

// Get only active teachers
$teachers = $selected_department ? $teacher->getActiveByDepartment($selected_department) : $teacher->getAllTeachers('active');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .teacher-photo-section {
            position: relative;
            height: 200px;
            background: linear-gradient(150deg, #ffffffff 0%, #2c3e50 50%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .teacher-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .default-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid white;
        }
        
        .default-photo i {
            font-size: 3rem;
            color: white;
        }
        
        .photo-upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #007bff;
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .photo-upload-btn:hover {
            background: #0056b3;
            transform: scale(1.1);
        }
        
        .teacher-info {
            padding: 20px;
        }
        
        .teacher-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        .teacher-department {
            color: #6c757d;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        
        .teacher-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .teacher-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }
        
        .teacher-actions .btn {
            flex: 1;
            font-size: 0.85rem;
        }
        
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }
        
        .upload-progress {
            display: none;
            margin-top: 10px;
        }
        
        .alert-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1055;
            min-width: 300px;
        }

        .form-required::after {
            content: " *";
            color: #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Manage Teachers</h3>
                <button class="btn btn-primary m-3" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                    <i class="fas fa-plus me-2"></i>Add Teacher
                </button>
            </div>

            <!-- Add Teacher Modal -->
            <div class="modal fade" id="addTeacherModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New Teacher</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" id="addTeacherForm">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="create">
                                <div class="mb-3">
                                    <label class="form-label form-required">Teacher Name</label>
                                    <input type="text" class="form-control" name="name" required 
                                           placeholder="Enter teacher's full name">
                                    <div class="invalid-feedback">Please enter the teacher's name.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label form-required">Department</label>
                                    <select class="form-select" name="department" required>
                                        <option value="">Select Department</option>
                                        <?php foreach($departments as $key => $label): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a department.</div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary" id="createTeacherBtn">
                                    <i class="fas fa-plus me-2"></i>Create Teacher
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
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

            <form method="get" class="mb-3 d-flex align-items-center">
                <label class="me-2 mb-0">Department:</label>
                <select name="department" class="form-select w-auto me-2" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php foreach($departments as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php if($selected_department == $key) echo 'selected'; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- Search Box -->
            <div class="mb-3">
                <div class="input-group" style="max-width: 528px;">
                    <span class="input-group-text p-3"><i class="fas fa-search"></i></span>
                    <input type="text" id="teacherSearch" class="form-control" placeholder="Search teachers by name...">
                </div>
            </div>

            <div class="teacher-cards-container" id="teacherCardsContainer">
                <?php if($teachers->rowCount() > 0): ?>
                    <?php while($row = $teachers->fetch(PDO::FETCH_ASSOC)): ?>
                    <div class="teacher-card" data-teacher-id="<?php echo $row['id']; ?>" data-teacher-name="<?php echo htmlspecialchars($row['name']); ?>">
                        <div class="teacher-photo-section">
                            <?php if(!empty($row['photo'])): ?>
                                <img src="../uploads/teachers/<?php echo htmlspecialchars($row['photo']); ?>?t=<?php echo time(); ?>" 
                                     alt="<?php echo htmlspecialchars($row['name']); ?>" 
                                     class="teacher-photo"
                                     onerror="handleImageError(this)">
                            <?php else: ?>
                                <div class="default-photo">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                            <button class="photo-upload-btn" onclick="openUploadForm(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['name']); ?>')">
                                <i class="fas fa-camera"></i>
                            </button>
                        </div>
                        
                        <div class="teacher-info">
                            <div class="teacher-name"><?php echo htmlspecialchars($row['name']); ?></div>
                            <div class="teacher-department"><?php echo htmlspecialchars($row['department']); ?></div>
                            
                            <div class="teacher-status badge bg-<?php echo $row['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo ucfirst($row['status']); ?>
                            </div>
                            
                            <div class="teacher-actions">
                                <a href="edit_teacher.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" style="display: inline; flex: 1;">
                                    <input type="hidden" name="teacher_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $row['status'] == 'active' ? 'deactivate' : 'activate'; ?>">
                                    <button type="submit" class="btn btn-sm btn-<?php echo $row['status'] == 'active' ? 'warning' : 'success'; ?> w-100">
                                        <i class="fas fa-<?php echo $row['status'] == 'active' ? 'user-slash' : 'user-check'; ?>"></i>
                                        <?php echo $row['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Teachers Found</h5>
                        <p class="text-muted">No active teachers found for the selected department.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                            <i class="fas fa-plus me-2"></i>Add First Teacher
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Photo Upload Modal -->
    <div class="modal fade" id="photoUploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadModalTitle">Upload Teacher Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="photoUploadForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="teacher_id" id="upload_teacher_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Select Photo</label>
                            <input type="file" class="form-control" id="teacher_photo" name="teacher_photo" accept="image/*" required>
                            <div class="form-text">Supported formats: JPG, PNG, GIF. Max size: 2MB</div>
                        </div>
                        
                        <div class="text-center">
                            <div id="photoPreview" style="display: none;">
                                <img id="previewImage" src="#" alt="Preview" class="img-thumbnail" style="max-height: 200px;">
                            </div>
                        </div>
                        
                        <div class="upload-progress" id="uploadProgress">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 0%"></div>
                            </div>
                            <div class="text-center mt-2">
                                <small>Uploading... <span id="progressPercent">0%</span></small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="uploadSubmitBtn">
                            <i class="fas fa-upload me-2"></i>Upload Photo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notifications -->
    <div class="alert-toast">
        <div id="uploadToast" class="toast align-items-center text-white bg-success border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body" id="toastMessage">
                    Photo uploaded successfully!
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        function handleImageError(img) {
            // Hide the broken image and show default avatar
            img.style.display = 'none';
            const defaultPhoto = document.createElement('div');
            defaultPhoto.className = 'default-photo';
            defaultPhoto.innerHTML = '<i class="fas fa-user"></i>';
            img.parentNode.insertBefore(defaultPhoto, img.nextSibling);
        }

        function openUploadForm(teacherId, teacherName) {
            document.getElementById('upload_teacher_id').value = teacherId;
            document.getElementById('uploadModalTitle').textContent = `Upload Photo for ${teacherName}`;
            
            // Reset form
            document.getElementById('photoUploadForm').reset();
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('uploadProgress').style.display = 'none';
            document.getElementById('uploadSubmitBtn').disabled = false;
            
            const modal = new bootstrap.Modal(document.getElementById('photoUploadModal'));
            modal.show();
        }

        // Photo preview functionality
        document.getElementById('teacher_photo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('photoPreview');
            const previewImage = document.getElementById('previewImage');
            
            if (file) {
                // Validate file size (2MB max)
                if (file.size > 2 * 1024 * 1024) {
                    showToast('File size too large. Please select an image under 2MB.', 'error');
                    this.value = '';
                    preview.style.display = 'none';
                    return;
                }
                
                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    showToast('Invalid file type. Please select JPG, PNG, or GIF images only.', 'error');
                    this.value = '';
                    preview.style.display = 'none';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        });

        // AJAX form submission for photo upload
        document.getElementById('photoUploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const teacherId = document.getElementById('upload_teacher_id').value;
            const formData = new FormData(this);
            const submitBtn = document.getElementById('uploadSubmitBtn');
            const progressBar = document.querySelector('.progress-bar');
            const progressPercent = document.getElementById('progressPercent');
            const progressContainer = document.getElementById('uploadProgress');
            
            // Show progress bar
            progressContainer.style.display = 'block';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
            
            const xhr = new XMLHttpRequest();
            
            // Progress tracking
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    progressBar.style.width = percentComplete + '%';
                    progressPercent.textContent = Math.round(percentComplete) + '%';
                }
            });
            
            // Completion handler
            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        // Update the teacher card with new photo
                        updateTeacherPhoto(teacherId, response.photo_url);
                        showToast('Photo uploaded successfully!', 'success');
                        
                        // Close modal after delay
                        setTimeout(() => {
                            bootstrap.Modal.getInstance(document.getElementById('photoUploadModal')).hide();
                        }, 1500);
                    } else {
                        showToast(response.message || 'Upload failed. Please try again.', 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Upload Photo';
                    }
                } else {
                    showToast('Upload failed. Please try again.', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Upload Photo';
                }
                
                progressContainer.style.display = 'none';
            });
            
            // Error handler
            xhr.addEventListener('error', function() {
                showToast('Upload failed. Please check your connection and try again.', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Upload Photo';
                progressContainer.style.display = 'none';
            });
            
            xhr.open('POST', 'upload_teacher_photo.php');
            xhr.send(formData);
        });

        // Add Teacher Form Validation and Submission
        document.getElementById('addTeacherForm').addEventListener('submit', function(e) {
            const form = this;
            const submitBtn = document.getElementById('createTeacherBtn');
            
            // Basic validation
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating...';
            
            // Form will submit normally via PHP
        });

        function updateTeacherPhoto(teacherId, photoUrl) {
            const teacherCard = document.querySelector(`.teacher-card[data-teacher-id="${teacherId}"]`);
            if (teacherCard) {
                const photoSection = teacherCard.querySelector('.teacher-photo-section');
                
                // Remove existing photo and default avatar
                const existingPhoto = photoSection.querySelector('.teacher-photo');
                const existingDefault = photoSection.querySelector('.default-photo');
                
                if (existingPhoto) existingPhoto.remove();
                if (existingDefault) existingDefault.remove();
                
                // Add new photo with cache busting
                const newPhoto = document.createElement('img');
                newPhoto.className = 'teacher-photo';
                newPhoto.src = photoUrl + '?t=' + new Date().getTime();
                newPhoto.alt = teacherCard.dataset.teacherName;
                newPhoto.onerror = function() { handleImageError(this); };
                
                // Insert before the upload button
                const uploadBtn = photoSection.querySelector('.photo-upload-btn');
                photoSection.insertBefore(newPhoto, uploadBtn);
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('uploadToast');
            const toastMessage = document.getElementById('toastMessage');
            
            // Set toast color based on type
            if (type === 'error') {
                toast.className = 'toast align-items-center text-white bg-danger border-0';
            } else {
                toast.className = 'toast align-items-center text-white bg-success border-0';
            }
            
            toastMessage.textContent = message;
            
            // Show toast
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
        }

        // Search functionality
        document.getElementById('teacherSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const teacherCards = document.querySelectorAll('.teacher-card');
            
            teacherCards.forEach(card => {
                const teacherName = card.dataset.teacherName.toLowerCase();
                if (teacherName.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Reset form when modal is closed
        document.getElementById('addTeacherModal').addEventListener('hidden.bs.modal', function () {
            const form = document.getElementById('addTeacherForm');
            form.reset();
            form.classList.remove('was-validated');
            document.getElementById('createTeacherBtn').disabled = false;
            document.getElementById('createTeacherBtn').innerHTML = '<i class="fas fa-plus me-2"></i>Create Teacher';
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add cache busting to all existing images
            document.querySelectorAll('.teacher-photo').forEach(img => {
                if (img.src && !img.src.includes('?')) {
                    img.src = img.src + '?t=' + new Date().getTime();
                }
            });
        });
    </script>
</body>
</html>