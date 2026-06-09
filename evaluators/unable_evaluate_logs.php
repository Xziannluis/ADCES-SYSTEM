<?php
require_once '../auth/session-check.php';

if (!empty($_GET['embedded'])) {
    header('X-Frame-Options: SAMEORIGIN');
}

$allowed_roles = ['dean', 'principal'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles, true)) {
    header('Location: ../login.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/program_assignments.php';

$db = (new Database())->getConnection();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS observer_unavailable_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            eval_id INT NOT NULL,
            evaluator_id INT NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            semester VARCHAR(10) NOT NULL,
            observation_date DATE NOT NULL,
            observation_time TIME NULL,
            department VARCHAR(100) NULL,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_observer_unavailable_eval (eval_id, evaluator_id),
            INDEX idx_observer_unavailable_slot (teacher_id, academic_year, semester, observation_date, observation_time),
            INDEX idx_observer_unavailable_eval (eval_id),
            INDEX idx_observer_unavailable_evaluator (evaluator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {}

$role = $_SESSION['role'] ?? '';
$sessionDept = trim((string)($_SESSION['department'] ?? ''));
$embedded = !empty($_GET['embedded']);

$allowedDepartments = $sessionDept !== '' ? [$sessionDept] : [];
$allowedDepartments = array_values(array_unique(array_filter($allowedDepartments)));

$department = $sessionDept;
$academicYear = trim((string)($_GET['academic_year'] ?? ''));
$semester = trim((string)($_GET['semester'] ?? ''));
$month = trim((string)($_GET['month'] ?? ''));
$observer = trim((string)($_GET['observer'] ?? ''));

$where = [];
$params = [];
$departmentExpr = "COALESCE(NULLIF(ous.department, ''), NULLIF(e.department, ''), NULLIF(t.scheduled_department, ''), t.department)";

if ($department !== '') {
    $where[] = "$departmentExpr = :department_scope";
    $params[':department_scope'] = $department;
} else {
    $where[] = '1 = 0';
}

if ($academicYear !== '') {
    $where[] = 'ous.academic_year = :academic_year';
    $params[':academic_year'] = $academicYear;
}
if (in_array($semester, ['1st', '2nd'], true)) {
    $where[] = 'ous.semester = :semester';
    $params[':semester'] = $semester;
}
if ($month !== '' && ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12) {
    $where[] = 'MONTH(ous.observation_date) = :month';
    $params[':month'] = (int)$month;
}
if ($observer !== '') {
    $where[] = 'u.name LIKE :observer';
    $params[':observer'] = '%' . $observer . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$logsStmt = $db->prepare("
    SELECT
        ous.*,
        t.name AS teacher_name,
        u.name AS observer_name,
        u.role AS observer_role,
        $departmentExpr AS display_department,
        COALESCE(NULLIF(e.subject_observed, ''), NULLIF(ts.subject, '')) AS subject_observed,
        COALESCE(NULLIF(e.subject_area, ''), NULLIF(ts.subject_area, '')) AS subject_area,
        COALESCE(NULLIF(e.observation_room, ''), NULLIF(ts.room, '')) AS room,
        ts.schedule_start,
        ts.schedule_end
    FROM observer_unavailable_slots ous
    JOIN teachers t ON t.id = ous.teacher_id
    JOIN users u ON u.id = ous.evaluator_id
    LEFT JOIN evaluations e ON e.id = ous.eval_id
    LEFT JOIN teacher_schedules ts ON ts.evaluation_id = ous.eval_id
    $whereSql
    ORDER BY ous.updated_at DESC, ous.created_at DESC, ous.observation_date DESC, ous.observation_time DESC
");
foreach ($params as $key => $value) {
    $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $logsStmt->bindValue($key, $value, $type);
}
$logsStmt->execute();
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$years = [];
try {
    $yearStmt = $db->prepare("
        SELECT DISTINCT ous.academic_year
        FROM observer_unavailable_slots ous
        JOIN teachers t ON t.id = ous.teacher_id
        LEFT JOIN evaluations e ON e.id = ous.eval_id
        WHERE ous.academic_year <> ''
          AND $departmentExpr = :department_scope
        ORDER BY ous.academic_year DESC
    ");
    $yearStmt->execute([':department_scope' => $department]);
    $years = $yearStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function roleLabel($role): string {
    return ucwords(str_replace('_', ' ', (string)$role));
}

function formatScheduleDate($date): string {
    if (empty($date)) return '';
    $ts = strtotime((string)$date);
    return $ts ? date('F d, Y', $ts) : (string)$date;
}

function formatTimeRange(array $row): string {
    $startRaw = trim((string)($row['schedule_start'] ?? ''));
    $endRaw = trim((string)($row['schedule_end'] ?? ''));
    if ($startRaw === '') {
        $date = trim((string)($row['observation_date'] ?? ''));
        $time = trim((string)($row['observation_time'] ?? ''));
        $startRaw = trim($date . ' ' . $time);
    }

    $start = $startRaw !== '' ? strtotime($startRaw) : false;
    $end = $endRaw !== '' ? strtotime($endRaw) : false;
    if ($start && $end) return date('g:i A', $start) . ' - ' . date('g:i A', $end);
    if ($start) return date('g:i A', $start);
    return '';
}

function splitReason($reason): array {
    $reason = trim((string)$reason);
    if ($reason === '') return ['reason' => 'Not specified', 'comments' => ''];
    $parts = explode('| Comments:', $reason, 2);
    return [
        'reason' => trim($parts[0]),
        'comments' => isset($parts[1]) ? trim($parts[1]) : ''
    ];
}

$totalLogs = count($logs);
$observerCount = count(array_unique(array_map(static fn($row) => (string)($row['evaluator_id'] ?? ''), $logs)));
$teacherCount = count(array_unique(array_map(static fn($row) => (string)($row['teacher_id'] ?? ''), $logs)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs for Unable to Evaluate</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .logs-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.04);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 12px;
        }
        .summary-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px 16px;
            background: #f8fafc;
        }
        .summary-item .label {
            color: #64748b;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .summary-item .value {
            color: #1f2937;
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1.1;
            margin-top: 4px;
        }
        .logs-table {
            min-width: 1120px;
            margin-bottom: 0;
        }
        .logs-table th {
            background: #2c3e50;
            color: #fff;
            font-size: 0.82rem;
            vertical-align: middle;
            white-space: nowrap;
        }
        .logs-table td {
            vertical-align: top;
            font-size: 0.85rem;
        }
        .reason-box {
            max-width: 320px;
            white-space: normal;
            line-height: 1.35;
        }
        .muted-line {
            color: #64748b;
            font-size: 0.78rem;
        }
        body.logs-embedded {
            background: #fff !important;
        }
        body.logs-embedded .main-content {
            margin-left: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: none !important;
            min-height: auto !important;
        }
        body.logs-embedded .dashboard-body-wrap {
            padding: 0 !important;
        }
        body.logs-embedded .container-fluid {
            padding: 16px !important;
            width: 100% !important;
            max-width: none !important;
        }
        body.logs-embedded .container-fluid > .d-flex:first-child {
            display: none !important;
        }
        body.logs-embedded .filters-card {
            border: 1px solid #e5e7eb;
            box-shadow: none;
            padding: 12px !important;
            margin-bottom: 12px !important;
        }
        body.logs-embedded .filters-card .row {
            --bs-gutter-x: 0.65rem;
            --bs-gutter-y: 0.65rem;
        }
        body.logs-embedded .filters-card .form-label {
            margin-bottom: 4px;
            font-size: 1rem;
            font-weight: 700;
            color: #475569;
        }
        body.logs-embedded .filters-card .form-select,
        body.logs-embedded .filters-card .form-control,
        body.logs-embedded .filters-card .btn {
            min-height: 46px;
            font-size: 1.08rem;
        }
        body.logs-embedded .summary-grid {
            grid-template-columns: repeat(3, minmax(120px, 1fr));
            gap: 8px;
            margin-bottom: 12px !important;
        }
        body.logs-embedded .summary-item {
            padding: 9px 12px;
            border-radius: 7px;
        }
        body.logs-embedded .summary-item .value {
            font-size: 1.65rem;
        }
        body.logs-embedded .summary-item .label {
            font-size: 0.95rem;
        }
        body.logs-embedded .logs-card {
            border: 1px solid #e5e7eb;
            box-shadow: none;
            overflow: hidden;
        }
        body.logs-embedded .table-responsive {
            overflow-x: auto;
        }
        body.logs-embedded .logs-table {
            width: 100%;
            min-width: 0;
            table-layout: fixed;
        }
        body.logs-embedded .logs-table th,
        body.logs-embedded .logs-table td {
            font-size: 1.08rem;
            padding: 13px 14px;
            overflow-wrap: anywhere;
        }
        body.logs-embedded .logs-table th {
            font-size: 1rem;
        }
        body.logs-embedded .logs-table th:nth-child(1),
        body.logs-embedded .logs-table td:nth-child(1),
        body.logs-embedded .logs-table th:nth-child(6),
        body.logs-embedded .logs-table td:nth-child(6) {
            display: none;
        }
        body.logs-embedded .logs-table th:nth-child(2) { width: 18%; }
        body.logs-embedded .logs-table th:nth-child(3) { width: 18%; }
        body.logs-embedded .logs-table th:nth-child(4) { width: 19%; }
        body.logs-embedded .logs-table th:nth-child(5) { width: 20%; }
        body.logs-embedded .logs-table th:nth-child(7) { width: 17%; }
        body.logs-embedded .logs-table th:nth-child(8) { width: 13%; }
        body.logs-embedded .reason-box {
            max-width: none;
        }
        body.logs-embedded .muted-line {
            font-size: 0.95rem;
        }
        @media print {
            .sidebar, .sidebar-backdrop, .mobile-sidebar-toggle, .mobile-sidebar-header, .dashboard-topbar, .filters-card, .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .dashboard-bg-layer {
                display: none !important;
            }
            .logs-card {
                box-shadow: none;
                border: none;
            }
            body {
                background: #fff !important;
            }
        }
    </style>
</head>
<body class="<?php echo $embedded ? 'logs-embedded' : ''; ?>">
    <?php if (!$embedded): ?>
    <?php include '../includes/sidebar.php'; ?>
    <?php endif; ?>

    <div class="main-content" style="padding:0;">
        <?php if (!$embedded): ?>
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="btn btn-primary no-print" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="evaluatorMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo h($_SESSION['name'] ?? 'User'); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="evaluatorMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-clipboard-list me-2"></i>Logs for Unable to Evaluate</h4>
                    <div class="text-muted">Records of observers/evaluators who marked a schedule as unable to observe/evaluate.</div>
                </div>
            </div>

            <div class="logs-card filters-card no-print p-3 mb-3">
                <form method="GET" class="row g-3 align-items-end">
                    <?php if ($embedded): ?>
                    <input type="hidden" name="embedded" value="1">
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <input type="hidden" name="department" value="<?php echo h($department); ?>">
                        <select class="form-select" disabled>
                            <?php foreach ($allowedDepartments as $dept): ?>
                                <option value="<?php echo h($dept); ?>" selected><?php echo h($dept); ?></option>
                            <?php endforeach; ?>
                            <?php if (empty($allowedDepartments)): ?>
                                <option value="">No department assigned</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Academic Year</label>
                        <select name="academic_year" class="form-select">
                            <option value="">All Years</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo h($year); ?>" <?php echo $academicYear === $year ? 'selected' : ''; ?>><?php echo h($year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $month === (string)$m ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Observer/Evaluator</label>
                        <input type="text" name="observer" class="form-control" value="<?php echo h($observer); ?>" placeholder="Search name">
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                    </div>
                </form>
            </div>

            <div class="summary-grid mb-3">
                <div class="summary-item">
                    <div class="label">Total Logs</div>
                    <div class="value"><?php echo (int)$totalLogs; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Observers</div>
                    <div class="value"><?php echo (int)$observerCount; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Teachers</div>
                    <div class="value"><?php echo (int)$teacherCount; ?></div>
                </div>
            </div>

            <div class="logs-card p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover logs-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Teacher</th>
                                <th>Observer/Evaluator</th>
                                <th>Schedule</th>
                                <th>Subject / Room</th>
                                <th>Department</th>
                                <th>Reason / Comments</th>
                                <th>Logged At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No unable-to-evaluate logs found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $idx => $row): ?>
                                    <?php
                                        $reasonParts = splitReason($row['reason'] ?? '');
                                        $scheduleDate = formatScheduleDate($row['observation_date'] ?? '');
                                        $timeRange = formatTimeRange($row);
                                    ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td>
                                            <strong><?php echo h($row['teacher_name'] ?? ''); ?></strong>
                                            <div class="muted-line">Eval ID: <?php echo (int)($row['eval_id'] ?? 0); ?></div>
                                        </td>
                                        <td>
                                            <strong><?php echo h($row['observer_name'] ?? ''); ?></strong>
                                            <div class="muted-line"><?php echo h(roleLabel($row['observer_role'] ?? '')); ?></div>
                                        </td>
                                        <td>
                                            <strong><?php echo h($scheduleDate); ?></strong>
                                            <?php if ($timeRange !== ''): ?>
                                                <div class="muted-line"><?php echo h($timeRange); ?></div>
                                            <?php endif; ?>
                                            <div class="muted-line"><?php echo h(($row['semester'] ?? '') . ' Semester ' . ($row['academic_year'] ?? '')); ?></div>
                                        </td>
                                        <td>
                                            <?php echo h($row['subject_observed'] ?? ''); ?>
                                            <?php if (!empty($row['subject_area'])): ?>
                                                <div class="muted-line"><?php echo h($row['subject_area']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['room'])): ?>
                                                <div class="muted-line">Room: <?php echo h($row['room']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h($row['display_department'] ?? ''); ?></td>
                                        <td>
                                            <div class="reason-box">
                                                <strong><?php echo h($reasonParts['reason']); ?></strong>
                                                <?php if ($reasonParts['comments'] !== ''): ?>
                                                    <div class="muted-line mt-1"><?php echo h($reasonParts['comments']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo h(!empty($row['updated_at']) ? date('F d, Y g:i A', strtotime((string)$row['updated_at'])) : ''); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
