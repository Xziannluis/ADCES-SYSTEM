<?php
require_once '../auth/session-check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

$currentRole = strtolower(str_replace(' ', '_', trim((string)($_SESSION['role'] ?? ''))));
if ($currentRole !== '') {
    $_SESSION['role'] = $currentRole;
}

if (!in_array($currentRole, ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

$teacherId = (int)($_GET['teacher_id'] ?? 0);
$evalId = (int)($_GET['eval_id'] ?? 0);
$obsDateRaw = trim((string)($_GET['observation_date'] ?? ''));
$obsTimeRaw = trim((string)($_GET['observation_time'] ?? ''));

$db = (new Database())->getConnection();
$slotAcademicYear = '';
$slotSemester = '';
$slotDepartment = '';

if ($evalId > 0) {
    $slotStmt = $db->prepare("SELECT teacher_id, observation_date, observation_time, academic_year, semester, department
                              FROM evaluations
                              WHERE id = :eval_id
                              LIMIT 1");
    $slotStmt->execute([':eval_id' => $evalId]);
    $slotRow = $slotStmt->fetch(PDO::FETCH_ASSOC);
    if ($slotRow) {
        $teacherId = (int)($slotRow['teacher_id'] ?? $teacherId);
        $obsDateRaw = trim((string)($slotRow['observation_date'] ?? $obsDateRaw));
        $obsTimeRaw = trim((string)($slotRow['observation_time'] ?? $obsTimeRaw));
        $slotAcademicYear = trim((string)($slotRow['academic_year'] ?? ''));
        $slotSemester = trim((string)($slotRow['semester'] ?? ''));
        $slotDepartment = trim((string)($slotRow['department'] ?? ''));
    }
}

if ($teacherId <= 0 || $obsDateRaw === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Missing parameters']);
    exit();
}

if ($currentRole === 'teacher') {
    $teacherAccessStmt = $db->prepare("
        SELECT 1
        FROM evaluations
        WHERE id = :eval_id
          AND evaluator_id = :uid
          AND status = 'completed'
          AND overall_avg IS NOT NULL
          AND overall_avg > 0
        LIMIT 1
    ");
    $teacherAccessStmt->execute([
        ':eval_id' => $evalId,
        ':uid' => (int)($_SESSION['user_id'] ?? 0)
    ]);
    if (!$teacherAccessStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit();
    }
}

$dateYmd = date('Y-m-d', strtotime($obsDateRaw));
$timeHm = '';
if ($obsTimeRaw !== '' && $obsTimeRaw !== '00:00' && $obsTimeRaw !== '00:00:00' && strtotime($obsTimeRaw) !== false) {
    $timeHm = date('H:i', strtotime($obsTimeRaw));
}

if (in_array($_SESSION['role'] ?? '', ['chairperson', 'subject_coordinator', 'grade_level_coordinator'], true)) {
    $accessSql = "SELECT 1
                  FROM evaluations e
                  WHERE e.teacher_id = :tid
                    AND DATE(e.observation_date) = :od";
    $accessParams = [
        ':tid' => $teacherId,
        ':od' => $dateYmd,
        ':uid' => (int)($_SESSION['user_id'] ?? 0),
        ':uid_assign' => (int)($_SESSION['user_id'] ?? 0),
    ];
    if ($timeHm !== '') {
        $accessSql .= " AND COALESCE(TIME_FORMAT(e.observation_time, '%H:%i'), '00:00') = :ot";
        $accessParams[':ot'] = $timeHm;
    }
    $accessSql .= " AND (
                        e.evaluator_id = :uid
                        OR EXISTS (
                            SELECT 1
                            FROM teacher_assignments ta
                            WHERE ta.teacher_id = e.teacher_id
                              AND ta.evaluator_id = :uid_assign
                              AND ta.eval_id = e.id
                        )
                    )
                    LIMIT 1";
    $accessStmt = $db->prepare($accessSql);
    $accessStmt->execute($accessParams);
    if (!$accessStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit();
    }
}

$commentsSql = "SELECT e.id, e.strengths, e.improvement_areas, e.recommendations, e.agreement, e.overall_avg,
                       u.name AS observer_name
                FROM evaluations e
                LEFT JOIN users u ON e.evaluator_id = u.id
                WHERE e.teacher_id = :tid
                  AND DATE(e.observation_date) = :od
                  AND (:ot_filter = '' OR COALESCE(TIME_FORMAT(e.observation_time, '%H:%i'), '00:00') = :ot_match)
                  AND (:ay_filter = '' OR e.academic_year = :ay_match)
                  AND (:sem_filter = '' OR e.semester = :sem_match)
                  AND (:dept_filter = '' OR e.department = :dept_match)
                  AND e.status = 'completed'
                  AND (:teacher_report_uid = 0 OR e.evaluator_id = :teacher_report_uid_match)
                ORDER BY e.created_at ASC, e.id ASC";
$stmt = $db->prepare($commentsSql);
$stmt->execute([
    ':tid' => $teacherId,
    ':od' => $dateYmd,
    ':ot_filter' => $timeHm,
    ':ot_match' => $timeHm,
    ':ay_filter' => $slotAcademicYear,
    ':ay_match' => $slotAcademicYear,
    ':sem_filter' => $slotSemester,
    ':sem_match' => $slotSemester,
    ':dept_filter' => $slotDepartment,
    ':dept_match' => $slotDepartment,
    ':teacher_report_uid' => $currentRole === 'teacher' ? (int)($_SESSION['user_id'] ?? 0) : 0,
    ':teacher_report_uid_match' => $currentRole === 'teacher' ? (int)($_SESSION['user_id'] ?? 0) : 0,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$items = [];
$ratingValues = [];
$observerNo = 1;
foreach ($rows as $r) {
    $eid = (int)($r['id'] ?? 0);
    if ($eid <= 0) continue;

    $dStmt = $db->prepare("SELECT comments FROM evaluation_details WHERE evaluation_id = :eid ORDER BY category, criterion_index");
    $dStmt->execute([':eid' => $eid]);
    $details = $dStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $strengths = [];
    $improvements = [];
    $recs = [];
    $agreements = [];

    foreach ($details as $d) {
        $c = trim((string)($d['comments'] ?? ''));
        if ($c === '') continue;
        $lc = strtolower($c);
        if (strpos($lc, 'recommend') !== false) $recs[] = $c;
        elseif (strpos($lc, 'agree') !== false || strpos($lc, 'acknowledge') !== false) $agreements[] = $c;
        elseif (strpos($lc, 'improve') !== false || strpos($lc, 'better') !== false || strpos($lc, 'suggestion') !== false) $improvements[] = $c;
        else $strengths[] = $c;
    }

    if (trim((string)($r['strengths'] ?? '')) !== '') $strengths[] = trim((string)$r['strengths']);
    if (trim((string)($r['improvement_areas'] ?? '')) !== '') $improvements[] = trim((string)$r['improvement_areas']);
    if (trim((string)($r['recommendations'] ?? '')) !== '') $recs[] = trim((string)$r['recommendations']);
    if (trim((string)($r['agreement'] ?? '')) !== '') $agreements[] = trim((string)$r['agreement']);

    $rating = isset($r['overall_avg']) ? (float)$r['overall_avg'] : 0.0;
    if ($rating > 0) {
        $ratingValues[] = $rating;
    }

    $items[] = [
        'observer' => trim((string)($r['observer_name'] ?? '')) !== '' ? trim((string)$r['observer_name']) : ('Observer ' . $observerNo),
        'rating' => $rating > 0 ? number_format($rating, 1) : '',
        'strengths' => array_values(array_unique($strengths)),
        'improvements' => array_values(array_unique($improvements)),
        'recommendations' => array_values(array_unique($recs)),
        'agreements' => array_values(array_unique($agreements)),
    ];
    $observerNo++;
}

$overallRating = !empty($ratingValues) ? array_sum($ratingValues) / count($ratingValues) : null;

echo json_encode([
    'ok' => true,
    'items' => $items,
    'overall_rating' => $overallRating !== null ? number_format($overallRating, 1) : '',
]);
