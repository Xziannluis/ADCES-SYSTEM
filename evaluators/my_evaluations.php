<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$db = (new Database())->getConnection();

// Find the evaluator's teacher record (if they also teach)
$teacher_query = "SELECT id, name, department, evaluation_schedule, evaluation_room FROM teachers WHERE user_id = :user_id LIMIT 1";
$teacher_stmt = $db->prepare($teacher_query);
$teacher_stmt->bindParam(':user_id', $_SESSION['user_id']);
$teacher_stmt->execute();
$teacher_data = $teacher_stmt->fetch(PDO::FETCH_ASSOC);

// Collect filter values
$filter_academic_year = $_GET['academic_year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_month = $_GET['month'] ?? '';

$evaluations = [];
if ($teacher_data) {
    $where_clauses = ['e.teacher_id = :teacher_id'];
    $params = [':teacher_id' => $teacher_data['id']];

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

    $query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role,
                     COALESCE(
                        (
                            SELECT NULLIF(ts.scheduled_department, '')
                            FROM teacher_schedules ts
                            WHERE ts.evaluation_id = e.id
                            ORDER BY ts.id DESC
                            LIMIT 1
                        ),
                        (
                            SELECT NULLIF(ts2.scheduled_department, '')
                            FROM teacher_schedules ts2
                            WHERE ts2.teacher_id = e.teacher_id
                              AND DATE(ts2.schedule_start) = e.observation_date
                              AND DATE_FORMAT(ts2.schedule_start, '%H:%i') = (
                                  CASE
                                      WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                                      ELSE LEFT(TRIM(e.observation_time), 5)
                                  END
                              )
                            ORDER BY ts2.id DESC
                            LIMIT 1
                        ),
                        NULLIF(e.department, ''),
                        NULLIF(u.department, '')
                     ) AS schedule_department,
                     COALESCE(
                        (
                            SELECT ts.schedule_end
                            FROM teacher_schedules ts
                            WHERE ts.evaluation_id = e.id
                            ORDER BY ts.id DESC
                            LIMIT 1
                        ),
                        (
                            SELECT ts2.schedule_end
                            FROM teacher_schedules ts2
                            WHERE ts2.teacher_id = e.teacher_id
                              AND DATE(ts2.schedule_start) = e.observation_date
                              AND DATE_FORMAT(ts2.schedule_start, '%H:%i') = (
                                  CASE
                                      WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                                      ELSE LEFT(TRIM(e.observation_time), 5)
                                  END
                              )
                            ORDER BY ts2.id DESC
                            LIMIT 1
                        ),
                        CASE
                            WHEN t.evaluation_schedule IS NOT NULL
                              AND DATE(t.evaluation_schedule) = e.observation_date
                              AND DATE_FORMAT(t.evaluation_schedule, '%H:%i') = (
                                  CASE
                                      WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                                      ELSE LEFT(TRIM(e.observation_time), 5)
                                  END
                              )
                            THEN t.evaluation_schedule_end
                            ELSE NULL
                        END
                     ) AS schedule_end_at
              FROM evaluations e
              JOIN users u ON e.evaluator_id = u.id
              LEFT JOIN teachers t ON t.id = e.teacher_id
              WHERE " . implode(' AND ', $where_clauses) . "
              ORDER BY e.created_at DESC";
    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resolve_schedule_department = static function($row): string {
        $dept = trim((string)($row['schedule_department'] ?? ''));
        if ($dept === '') {
            $dept = trim((string)($row['department'] ?? ''));
        }
        return $dept;
    };

    // 1) Build completed-index for strict and loose slot matching.
    $completedStrict = [];
    $completedLoose = [];
    foreach ($evaluations as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status !== 'completed') continue;
        $ft = strtolower(trim((string)($row['evaluation_form_type'] ?? 'iso')));
        if ($ft === '') $ft = 'iso';
        $strict = implode('|', [
            (string)($row['evaluator_id'] ?? ''),
            $ft,
            (string)($row['academic_year'] ?? ''),
            (string)($row['semester'] ?? ''),
            (string)($row['observation_date'] ?? ''),
            (string)($row['observation_time'] ?? ''),
            $resolve_schedule_department($row),
        ]);
        $loose = implode('|', [
            (string)($row['evaluator_id'] ?? ''),
            $ft,
            (string)($row['academic_year'] ?? ''),
            (string)($row['semester'] ?? ''),
            (string)($row['observation_date'] ?? ''),
            $resolve_schedule_department($row),
        ]);
        $completedStrict[$strict] = true;
        $completedLoose[$loose] = true;
    }

    // 2) Drop non-completed rows that already have a matching completed row.
    $filtered = [];
    foreach ($evaluations as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $ft = strtolower(trim((string)($row['evaluation_form_type'] ?? 'iso')));
        if ($ft === '') $ft = 'iso';
        if ($status !== 'completed') {
            $strict = implode('|', [
                (string)($row['evaluator_id'] ?? ''),
                $ft,
                (string)($row['academic_year'] ?? ''),
                (string)($row['semester'] ?? ''),
                (string)($row['observation_date'] ?? ''),
                (string)($row['observation_time'] ?? ''),
                $resolve_schedule_department($row),
            ]);
            $loose = implode('|', [
                (string)($row['evaluator_id'] ?? ''),
                $ft,
                (string)($row['academic_year'] ?? ''),
                (string)($row['semester'] ?? ''),
                (string)($row['observation_date'] ?? ''),
                $resolve_schedule_department($row),
            ]);
            if (isset($completedStrict[$strict]) || isset($completedLoose[$loose])) {
                continue;
            }
        }
        $filtered[] = $row;
    }

    // 3) Collapse remaining exact duplicates and prefer completed/newest.
    $slotMap = [];
    foreach ($filtered as $row) {
        $ft = strtolower(trim((string)($row['evaluation_form_type'] ?? 'iso')));
        if ($ft === '') $ft = 'iso';
        $slotKey = implode('|', [
            (string)($row['evaluator_id'] ?? ''),
            $ft,
            (string)($row['academic_year'] ?? ''),
            (string)($row['semester'] ?? ''),
            (string)($row['observation_date'] ?? ''),
            (string)($row['observation_time'] ?? ''),
            $resolve_schedule_department($row),
        ]);

        if (!isset($slotMap[$slotKey])) {
            $slotMap[$slotKey] = $row;
            continue;
        }

        $keep = $slotMap[$slotKey];
        $keepCompleted = (strtolower((string)($keep['status'] ?? '')) === 'completed');
        $rowCompleted = (strtolower((string)($row['status'] ?? '')) === 'completed');
        if ($rowCompleted && !$keepCompleted) {
            $slotMap[$slotKey] = $row;
        } elseif ($rowCompleted === $keepCompleted && (int)($row['id'] ?? 0) > (int)($keep['id'] ?? 0)) {
            $slotMap[$slotKey] = $row;
        }
    }
    $evaluations = array_values($slotMap);

    // Keep newest first for display
    usort($evaluations, function ($a, $b) {
        return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
    });

    $normalize_schedule_date = static function($value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : $value;
    };

    $normalize_schedule_time = static function($value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        $ts = strtotime($value);
        if ($ts) return date('H:i', $ts);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $m)) {
            return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }
        return $value;
    };

    $normalize_subject_slot = static function($value): string {
        $value = strtolower(trim((string)$value));
        if ($value === '') return '';
        $value = preg_replace('/\s+\d{1,2}:\d{2}\s*(am|pm)(?:\s*-\s*(?:\d{1,2}:\d{2}\s*(am|pm))?)?\s*$/i', '', $value);
        return trim((string)$value);
    };

    $is_schedule_past_cutoff = static function($row): bool {
        $cutoffRaw = trim((string)($row['schedule_end_at'] ?? ''));
        if ($cutoffRaw === '') {
            $obsDate = trim((string)($row['observation_date'] ?? ''));
            $obsTime = trim((string)($row['observation_time'] ?? ''));
            if ($obsDate !== '' && $obsTime !== '') {
                $cutoffRaw = $obsDate . ' ' . $obsTime;
            }
        }
        if ($cutoffRaw === '') {
            return false;
        }
        try {
            $tz = new DateTimeZone('Asia/Manila');
            $cutoffAt = new DateTime($cutoffRaw, $tz);
            $cutoffAt->setTimezone($tz);
            $nowAt = new DateTime('now', $tz);
            return $nowAt > $cutoffAt;
        } catch (Exception $e) {
            return false;
        }
    };

    // 4) Collapse to one card per schedule slot and summarize form completion.
    $grouped = [];
    foreach ($evaluations as $row) {
        $groupKey = implode('|', [
            (string)($row['academic_year'] ?? ''),
            (string)($row['semester'] ?? ''),
            $normalize_schedule_date($row['observation_date'] ?? ''),
            $normalize_schedule_time($row['observation_time'] ?? ''),
            $normalize_subject_slot($row['subject_observed'] ?? ''),
            $resolve_schedule_department($row),
        ]);
        $ft = strtolower(trim((string)($row['evaluation_form_type'] ?? 'iso')));
        if (!in_array($ft, ['iso', 'peac'], true)) $ft = 'iso';
        $isCompleted = (strtolower(trim((string)($row['status'] ?? ''))) === 'completed');
        $isSigned = !empty($row['rater_signature']);
        $isPastCutoff = (!$isCompleted && $is_schedule_past_cutoff($row));

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'row' => $row,
                'forms' => ['iso' => false, 'peac' => false],
                'has_completed' => false,
                'is_closed' => false,
                'completed_count' => 0,
                'signed_count' => 0,
                'unsigned_eval_id' => 0
            ];
        }
        if ($isPastCutoff) {
            $grouped[$groupKey]['is_closed'] = true;
        }

        if ($isCompleted) {
            $grouped[$groupKey]['forms'][$ft] = true;
            $grouped[$groupKey]['has_completed'] = true;
            $grouped[$groupKey]['completed_count']++;
            if ($isSigned) {
                $grouped[$groupKey]['signed_count']++;
            } elseif (empty($grouped[$groupKey]['unsigned_eval_id'])) {
                $grouped[$groupKey]['unsigned_eval_id'] = (int)($row['id'] ?? 0);
            }
        }

        // Prefer completed row for view button; else keep latest row by id.
        $keep = $grouped[$groupKey]['row'];
        $keepCompleted = (strtolower(trim((string)($keep['status'] ?? ''))) === 'completed');
        if (($isCompleted && !$keepCompleted) || ((int)($row['id'] ?? 0) > (int)($keep['id'] ?? 0) && $isCompleted === $keepCompleted)) {
            $grouped[$groupKey]['row'] = $row;
        }
    }

    $evaluations = [];
    foreach ($grouped as $g) {
        $row = $g['row'];
        $isoDone = !empty($g['forms']['iso']);
        $peacDone = !empty($g['forms']['peac']);
        if ($isoDone && $peacDone) {
            $row['forms_status_text'] = 'ISO & PEAC Done';
        } elseif ($isoDone) {
            $row['forms_status_text'] = 'ISO Done';
        } elseif ($peacDone) {
            $row['forms_status_text'] = 'PEAC Done';
        } else {
            $row['forms_status_text'] = 'No completed form yet';
        }
        if (!empty($g['has_completed'])) {
            $row['status'] = 'completed';
        } elseif (!empty($g['is_closed'])) {
            $row['status'] = 'closed';
        }
        $row['completed_count'] = (int)($g['completed_count'] ?? 0);
        $row['signed_count'] = (int)($g['signed_count'] ?? 0);
        $row['has_signed'] = $row['completed_count'] > 0 && $row['signed_count'] >= $row['completed_count'];
        $row['sign_eval_id'] = !empty($g['unsigned_eval_id']) ? (int)$g['unsigned_eval_id'] : (int)($row['id'] ?? 0);
        $evaluations[] = $row;
    }

    usort($evaluations, function ($a, $b) {
        return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Evaluations - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .eval-info-card {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .eval-info-card h3 { margin: 0; font-weight: 700; }
        .eval-info-card p { margin: 5px 0 0 0; opacity: 0.9; }
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
        .badge-closed { background: #f8d7da; color: #842029; }
        .signature-canvas {
            border: 2px solid #dee2e6;
            border-radius: 4px;
            background: white;
            cursor: crosshair;
            display: block;
            touch-action: none;
        }
        .btn-sign {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
            font-weight: 600;
        }
        .btn-sign:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 87, 108, 0.4);
            color: white;
        }
        .sign-badge {
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            margin-left: 5px;
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
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

            <!-- Info Card -->
            <div class="eval-info-card">
                <h3><i class="fas fa-file-alt me-2"></i>My Evaluations</h3>
                <p><i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['name']); ?> &mdash; <?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></p>
                <?php if($teacher_data): ?>
                <p><i class="fas fa-building me-2"></i>Department: <?php echo htmlspecialchars($teacher_data['department']); ?></p>
                <?php endif; ?>
            </div>

            <?php if(!$teacher_data): ?>
            <!-- No teacher record -->
            <div class="content-area">
                <div class="no-evaluations">
                    <i class="fas fa-info-circle"></i>
                    <h5>No Teacher Record Found</h5>
                    <p>You don't have a teacher record linked to your account. Contact the EDP to set up your teaching profile if you also teach.</p>
                </div>
            </div>
            <?php else: ?>

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
                        <a href="my_evaluations.php" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Reset</a>
                    </div>
                </form>
            </div>

            <!-- Evaluations List -->
            <div class="content-area">
                <h4 class="mb-4">
                    <i class="fas fa-clipboard-list me-2"></i>Evaluation History
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
                    <?php $evalStatus = strtolower(trim((string)($eval['status'] ?? ''))); ?>
                    <div class="evaluation-card">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="card-title">
                                    <i class="fas fa-calendar me-2"></i>
                                    Observation Date: <?php
                                        $obsDate = trim((string)($eval['observation_date'] ?? ''));
                                        echo $obsDate !== '' ? date('F d, Y', strtotime($obsDate)) : 'Not specified';
                                    ?>
                                    <span class="ms-2">
                                        <?php if($evalStatus === 'completed'): ?>
                                            <span class="badge-status badge-completed">
                                                <i class="fas fa-check-circle me-1"></i>Completed
                                            </span>
                                        <?php elseif($evalStatus === 'closed'): ?>
                                            <span class="badge-status badge-closed">
                                                <i class="fas fa-times-circle me-1"></i>Closed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-pending">
                                                <i class="fas fa-clock me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </h6>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-layer-group me-2"></i>
                                    Form Completion: <strong><?php echo htmlspecialchars($eval['forms_status_text'] ?? ''); ?></strong>
                                </p>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-calendar me-2"></i>
                                    Submitted: <?php echo date('F d, Y h:i A', strtotime($eval['created_at'])); ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <?php if($evalStatus === 'completed'): ?>
                                    <?php $hasSigned = !empty($eval['has_signed']); ?>
                                    <div class="d-flex gap-2 justify-content-md-end align-items-center flex-wrap">
                                        <a href="view_my_evaluation.php?eval_id=<?php echo $eval['id']; ?>" class="btn-view">
                                            <i class="fas fa-eye me-2"></i>View
                                        </a>
                                        <?php if(!$hasSigned): ?>
                                        <button type="button" class="btn-sign" data-bs-toggle="modal" data-bs-target="#signModal" onclick="prepareSignModal(<?php echo (int)($eval['sign_eval_id'] ?? $eval['id']); ?>)">
                                            <i class="fas fa-pen me-1"></i>Sign
                                            <span class="sign-badge">!</span>
                                        </button>
                                        <?php else: ?>
                                        <span class="badge-status badge-completed">
                                            <i class="fas fa-check me-1"></i>Signed
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif($evalStatus === 'closed'): ?>
                                <span class="text-muted">
                                    <i class="fas fa-calendar-times me-2"></i>Schedule Ended
                                </span>
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
                        <p>You don't have any evaluations yet. Once you are evaluated, it will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>

        </div>
        </div>
    </div>

    <!-- Sign Modal -->
    <div class="modal fade" id="signModal" tabindex="-1" aria-labelledby="signModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="signModalLabel">
                        <i class="fas fa-pen me-2"></i>Sign Evaluation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Printed Name</label>
                        <input type="text" class="form-control" id="signatureName" value="<?php echo htmlspecialchars($_SESSION['name']); ?>" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Signature</label>
                        <p class="form-text text-muted">Please sign below using your mouse or touchpad. Ensure your entire signature fits within the box.</p>
                        <canvas id="signaturePad" class="signature-canvas" width="100" height="200"></canvas>
                        <div class="form-text mt-2">
                            <small><i class="fas fa-info-circle me-1"></i>Sign in the box above</small>
                        </div>
                    </div>

                    <input type="hidden" id="evaluationId" value="">
                    <input type="hidden" id="signatureData" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" class="form-control" id="signatureDate" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="clearSignBtn">
                        <i class="fas fa-eraser me-2"></i>Clear
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="submitSignBtn">
                        <i class="fas fa-check me-2"></i>Sign & Submit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
    <script>
    (function() {
        // Semester-to-months mapping (adjust ranges as needed for your institution)
        const semesterMonths = {
            '1st': [6,7,8,9,10],      // June–October
            '2nd': [11,12,1,2,3],      // November–March
            'Summer': [4,5]             // April–May
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
            // Reset month when semester changes (unless page just loaded)
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

        // Initialize on page load
        updateMonths();
    })();

    // Signature Canvas Functions
    function createSignaturePad(canvas) {
        const ctx = canvas.getContext('2d');
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#000';

        let drawing = false;
        let hasInk = false;

        function getPoint(evt) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            return {
                x: (evt.clientX - rect.left) * scaleX,
                y: (evt.clientY - rect.top) * scaleY
            };
        }

        function pointerDown(evt) {
            evt.preventDefault();
            drawing = true;
            const p = getPoint(evt);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        }

        function pointerMove(evt) {
            if (!drawing) return;
            evt.preventDefault();
            const p = getPoint(evt);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hasInk = true;
        }

        function pointerUp(evt) {
            if (!drawing) return;
            evt.preventDefault();
            drawing = false;
        }

        canvas.addEventListener('pointerdown', pointerDown);
        canvas.addEventListener('pointermove', pointerMove);
        canvas.addEventListener('pointerup', pointerUp);
        canvas.addEventListener('pointercancel', pointerUp);
        canvas.addEventListener('pointerleave', pointerUp);

        return {
            clear() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasInk = false;
            },
            toDataUrl() {
                if (!hasInk) return '';
                try { return canvas.toDataURL('image/png'); } catch (e) { return ''; }
            }
        };
    }

    let signaturePad = null;

    function prepareSignModal(evalId) {
        document.getElementById('evaluationId').value = evalId;
        
        // Set today's date
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('signatureDate').value = today;

        // Initialize signature pad if not already done
        if (!signaturePad) {
            const canvas = document.getElementById('signaturePad');
            canvas.width = canvas.offsetWidth;
            signaturePad = createSignaturePad(canvas);
        } else {
            // Clear previous signature
            signaturePad.clear();
        }
    }

    // Clear signature button
    document.getElementById('clearSignBtn')?.addEventListener('click', function() {
        if (signaturePad) {
            signaturePad.clear();
        }
    });

    // Submit signature
    document.getElementById('submitSignBtn')?.addEventListener('click', function() {
        if (!signaturePad) {
            alert('Signature pad not initialized');
            return;
        }

        const signatureData = signaturePad.toDataUrl();
        if (!signatureData) {
            alert('Please sign the signature pad first.');
            return;
        }

        const evalId = document.getElementById('evaluationId').value;
        const signDate = document.getElementById('signatureDate').value;

        if (!evalId || !signDate) {
            alert('Missing required fields');
            return;
        }

        // Show loading state
        const btn = document.getElementById('submitSignBtn');
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

        // Send to backend
        fetch('save_signature.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                evaluation_id: evalId,
                signature: signatureData,
                signature_date: signDate,
                signer_name: document.getElementById('signatureName').value
            })
        })
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;

            if (data.success) {
                alert('Signature submitted successfully!');
                // Close modal and refresh page
                const modal = bootstrap.Modal.getInstance(document.getElementById('signModal'));
                modal.hide();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to save signature'));
            }
        })
        .catch(error => {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            alert('Error: ' + error.message);
        });
    });
    </script>
</body>
</html>

