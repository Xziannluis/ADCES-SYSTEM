<?php
require_once '../auth/session-check.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

$teacherId = (int)($_GET['teacher_id'] ?? 0);
$obsDateRaw = trim((string)($_GET['observation_date'] ?? ''));
$obsTimeRaw = trim((string)($_GET['observation_time'] ?? ''));

if ($teacherId <= 0 || $obsDateRaw === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Missing parameters']);
    exit();
}

$db = (new Database())->getConnection();
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
        $accessSql .= " AND (
                            CASE
                                WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                                ELSE LEFT(TRIM(e.observation_time), 5)
                            END
                        ) = :ot";
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

$stmt = $db->prepare("SELECT e.id, e.strengths, e.improvement_areas, e.recommendations, e.agreement, e.overall_avg,
                             u.name AS observer_name
                      FROM evaluations e
                      LEFT JOIN users u ON e.evaluator_id = u.id
                      WHERE e.teacher_id = :tid AND DATE(e.observation_date) = :od
                        AND (
                            :ot_filter = ''
                            OR (
                                CASE
                                    WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                                    ELSE LEFT(TRIM(e.observation_time), 5)
                                END
                            ) = :ot_match
                        )
                        AND e.status = 'completed'
                      ORDER BY e.created_at ASC, e.id ASC");
$stmt->execute([':tid' => $teacherId, ':od' => $dateYmd, ':ot_filter' => $timeHm, ':ot_match' => $timeHm]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$items = [];
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

    $items[] = [
        'observer' => trim((string)($r['observer_name'] ?? '')) !== '' ? trim((string)$r['observer_name']) : ('Observer ' . $observerNo),
        'rating' => isset($r['overall_avg']) ? number_format((float)$r['overall_avg'], 1) : '',
        'strengths' => array_values(array_unique($strengths)),
        'improvements' => array_values(array_unique($improvements)),
        'recommendations' => array_values(array_unique($recs)),
        'agreements' => array_values(array_unique($agreements)),
    ];
    $observerNo++;
}

echo json_encode(['ok' => true, 'items' => $items]);
