<?php
session_start();

// Check if teacher is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';

$database = new Database();
$db = $database->getConnection();

// remove any past schedules so teacher doesn't see outdated entries
// Clear schedules that are more than 24 hours past their scheduled time
try {
    $db->exec("UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, evaluation_form_type = 'iso', updated_at = NOW() WHERE evaluation_schedule IS NOT NULL AND evaluation_schedule < NOW() - INTERVAL 24 HOUR");
} catch (Exception $e) {
    error_log('Failed to clear expired schedules: ' . $e->getMessage());
}

// Also clear schedules for teachers who already have a completed evaluation this period
try {
    $month = (int)date('n');
    $year = (int)date('Y');
    $curAY = ($month >= 6) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
    $curSem = ($month >= 6 && $month <= 10) ? '1st' : '2nd';
    $db->prepare("UPDATE teachers t
        INNER JOIN evaluations e ON e.teacher_id = t.id AND e.status = 'completed'
            AND e.academic_year = :ay AND e.semester = :sem
        SET t.evaluation_schedule = NULL, t.evaluation_room = NULL, t.evaluation_focus = NULL,
            t.evaluation_subject_area = NULL, t.evaluation_subject = NULL, t.evaluation_semester = NULL,
            t.evaluation_form_type = 'iso', t.updated_at = NOW()
        WHERE t.evaluation_schedule IS NOT NULL
          AND (t.evaluation_form_type IS NULL OR t.evaluation_form_type != 'both'
               OR (SELECT COUNT(*) FROM evaluations e2 WHERE e2.teacher_id = t.id AND e2.status = 'completed'
                   AND e2.academic_year = :ay2 AND e2.semester = :sem2 AND e2.evaluation_form_type = 'peac') > 0)")
        ->execute([':ay' => $curAY, ':sem' => $curSem, ':ay2' => $curAY, ':sem2' => $curSem]);
} catch (Exception $e) {
    error_log('Error clearing completed-eval schedules: ' . $e->getMessage());
}

$teacher = new Teacher($db);
$evaluation = new Evaluation($db);

// Get teacher info
$teacher_data = $teacher->getById($_SESSION['teacher_id']);
if(!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: ../login.php");
    exit();
}

// Collect filter values
$filter_academic_year = $_GET['academic_year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_month = $_GET['month'] ?? '';

// Build filtered query
$where_clauses = ['e.teacher_id = :teacher_id'];
$params = [':teacher_id' => $_SESSION['teacher_id']];

if (!empty($filter_academic_year)) {
    $where_clauses[] = 'e.academic_year = :academic_year';
    $params[':academic_year'] = $filter_academic_year;
}
if (!empty($filter_semester)) {
    $where_clauses[] = 'e.semester = :semester';
    $params[':semester'] = $filter_semester;
}
if (!empty($filter_month)) {
    $where_clauses[] = 'MONTH(e.observation_date) = :obs_month';
    $params[':obs_month'] = (int) $filter_month;
}

$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role 
          FROM evaluations e
          JOIN users u ON e.evaluator_id = u.id
          WHERE " . implode(' AND ', $where_clauses) . "
          ORDER BY e.created_at DESC";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-info-card {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .teacher-info-card h3 {
            margin: 0;
            font-weight: 700;
        }
        .teacher-info-card p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }
        .schedule-bell {
            border: none;
            background: #fff;
            color: #2c3e50;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .schedule-bell .schedule-dot {
            position: absolute;
            top: 2px; right: 2px;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #dc3545;
            border: 1px solid #fff;
            display: none;
        }
        .schedule-bell.show-dot .schedule-dot { display: inline-block; }
        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .evaluation-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .evaluation-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .evaluation-card .card-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .btn-view {
            background: #3498db;
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        .btn-view:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
        }
        .no-evaluations {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: #6c757d;
        }
        .no-evaluations i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-completed { background: #d4edda; color: #155724; }
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="teacherMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (Teacher)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
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

            <!-- Teacher Info Card -->
            <div class="teacher-info-card">
                <h3>
                    Welcome, <?php echo htmlspecialchars($teacher_data['name']); ?>!
                    <button type="button" class="btn btn-sm schedule-bell ms-2 position-relative" id="scheduleBell" data-bs-toggle="popover" data-bs-placement="bottom">
                        <i class="fas fa-bell"></i>
                        <span class="schedule-dot"></span>
                    </button>
                </h3>
                <p><i class="fas fa-building me-2"></i>Department: <?php echo htmlspecialchars($teacher_data['department']); ?></p>
                <p><i class="fas fa-check-circle me-2"></i>Status: <span class="badge bg-success">Active</span></p>
            </div>

            <!-- Evaluation Schedule Info -->
            <div class="content-area">
                <h4 class="mb-4">
                    <i class="fas fa-calendar-alt me-2"></i>Evaluation Schedule & Room
                </h4>
                
                <?php if($teacher_data['evaluation_schedule']): ?>
                <div class="alert alert-info">
                    <div class="row">
                        <div class="col-md-6">
                            <strong><i class="fas fa-clock me-2"></i>Scheduled Date & Time:</strong>
                            <p class="mb-0"><?php echo date('F d, Y \a\t h:i A', strtotime($teacher_data['evaluation_schedule'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-door-open me-2"></i>Room Location:</strong>
                            <p class="mb-0"><?php echo htmlspecialchars($teacher_data['evaluation_room'] ?? 'Not assigned yet'); ?></p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>No evaluation schedule assigned yet.</strong> 
                </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="content-area">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Evaluations</h5>
                <form method="GET" id="filterForm" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="academic_year" class="form-label fw-semibold">Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-select">
                            <option value="">All Academic Years</option>
                            <option value="2025-2026" <?php echo ($filter_academic_year === '2025-2026') ? 'selected' : ''; ?>>2025-2026</option>
                            <option value="2026-2027" <?php echo ($filter_academic_year === '2026-2027') ? 'selected' : ''; ?>>2026-2027</option>
                            <option value="2027-2028" <?php echo ($filter_academic_year === '2027-2028') ? 'selected' : ''; ?>>2027-2028</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="semester" class="form-label fw-semibold">Semester</label>
                        <select name="semester" id="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo ($filter_semester === '1st') ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo ($filter_semester === '2nd') ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="month" class="form-label fw-semibold">Month</label>
                        <select name="month" id="month" class="form-select">
                            <option value="">All Months</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
                        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Reset</a>
                    </div>
                </form>
            </div>

            <!-- Evaluations List -->
            <div class="content-area">
                <h4 class="mb-4">
                    <i class="fas fa-file-pdf me-2"></i>My Evaluations
                    <?php if(!empty($filter_academic_year) || !empty($filter_semester) || !empty($filter_month)): ?>
                    <small class="text-muted fs-6">
                        (Filtered: <?php
                            $parts = [];
                            if(!empty($filter_academic_year)) $parts[] = $filter_academic_year;
                            if(!empty($filter_semester)) $parts[] = $filter_semester . ' Semester';
                            if(!empty($filter_month)) {
                                $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                                $parts[] = $monthNames[(int)$filter_month] ?? '';
                            }
                            echo htmlspecialchars(implode(' / ', $parts));
                        ?>)
                    </small>
                    <?php endif; ?>
                </h4>

                <?php if(count($evaluations) > 0): ?>
                    <?php foreach($evaluations as $eval): ?>
                    <div class="evaluation-card">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="card-title">
                                    Evaluation by <?php echo htmlspecialchars($eval['evaluator_name']); ?>
                                    <span class="ms-2">
                                        <?php if($eval['status'] === 'completed'): ?>
                                            <span class="badge-status badge-completed">
                                                <i class="fas fa-check-circle me-1"></i>Completed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-pending">
                                                <i class="fas fa-clock me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </h6>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-user-tag me-2"></i>
                                    Evaluator Role: <strong><?php echo ucfirst(str_replace('_', ' ', $eval['evaluator_role'])); ?></strong>
                                </p>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-calendar me-2"></i>
                                    Submitted: <?php echo date('F d, Y h:i A', strtotime($eval['created_at'])); ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <?php if($eval['status'] === 'completed'): ?>
                                <a href="view-evaluation.php?eval_id=<?php echo $eval['id']; ?>" class="btn-view">
                                    <i class="fas fa-eye me-2"></i>View 
                                </a>
                                <?php else: ?>
                                <span class="text-muted">
                                    <i class="fas fa-hourglass-half me-2"></i>Awaiting Completion
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-evaluations">
                        <i class="fas fa-inbox"></i>
                        <h5>No Evaluations Yet</h5>
                        <p>You don't have any evaluations yet. Once evaluators complete your evaluation, it will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        (function () {
            const bell = document.getElementById('scheduleBell');
            if (!bell) return;

            const scheduleText = <?php echo json_encode(!empty($teacher_data['evaluation_schedule'])
                ? date('F d, Y \a\t h:i A', strtotime($teacher_data['evaluation_schedule']))
                : 'No schedule assigned'); ?>;
            const roomText = <?php echo json_encode($teacher_data['evaluation_room'] ?? 'Not assigned yet'); ?>;
            const hasSchedule = <?php echo !empty($teacher_data['evaluation_schedule']) ? 'true' : 'false'; ?>;
            const teacherId = <?php echo json_encode($teacher_data['id'] ?? ''); ?>;
            const scheduleKey = `schedule_seen_${teacherId}_${scheduleText}_${roomText}`;

            const content = hasSchedule
                ? `<div><strong>Schedule:</strong> ${scheduleText}</div><div><strong>Room:</strong> ${roomText}</div>`
                : '<div>No schedule assigned yet.</div>';

            new bootstrap.Popover(bell, {
                html: true,
                trigger: 'focus',
                content: content
            });

            if (hasSchedule && !localStorage.getItem(scheduleKey)) {
                bell.classList.add('show-dot');
            }

            bell.addEventListener('click', () => {
                if (!hasSchedule) return;
                localStorage.setItem(scheduleKey, 'seen');
                bell.classList.remove('show-dot');
            });
        })();
    </script>
    <?php include '../includes/email_verify_prompt.php'; ?>
    <script>
    (function() {
        const semesterMonths = {
            '1st': [6,7,8,9,10],
            '2nd': [11,12,1,2,3],
            'Summer': [4,5]
        };
        const allMonths = [
            {value: 1, label: 'January'}, {value: 2, label: 'February'}, {value: 3, label: 'March'},
            {value: 4, label: 'April'}, {value: 5, label: 'May'}, {value: 6, label: 'June'},
            {value: 7, label: 'July'}, {value: 8, label: 'August'}, {value: 9, label: 'September'},
            {value: 10, label: 'October'}, {value: 11, label: 'November'}, {value: 12, label: 'December'}
        ];

        const semesterSelect = document.getElementById('semester');
        const monthSelect = document.getElementById('month');
        const selectedMonth = <?php echo json_encode($filter_month); ?>;

        function updateMonths() {
            const sem = semesterSelect.value;
            monthSelect.innerHTML = '<option value="">All Months</option>';
            let months = allMonths;
            if (sem && semesterMonths[sem]) {
                const allowed = semesterMonths[sem];
                months = allMonths.filter(m => allowed.includes(m.value));
            }
            months.forEach(function(m) {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.label;
                if (String(m.value) === String(selectedMonth)) opt.selected = true;
                monthSelect.appendChild(opt);
            });
        }

        semesterSelect.addEventListener('change', function() {
            monthSelect.innerHTML = '<option value="">All Months</option>';
            const sem = this.value;
            let months = allMonths;
            if (sem && semesterMonths[sem]) {
                const allowed = semesterMonths[sem];
                months = allMonths.filter(m => allowed.includes(m.value));
            }
            months.forEach(function(m) {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.label;
                monthSelect.appendChild(opt);
            });
        });

        updateMonths();
    })();
    </script>
</body>
</html>
