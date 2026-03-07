<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$sql = "
    SELECT
        e.id,
        e.subject_observed,
        e.observation_type,
        e.communications_avg,
        e.management_avg,
        e.assessment_avg,
        e.overall_avg,
        e.strengths,
        e.improvement_areas,
        e.recommendations,
        e.created_at,
        COALESCE(t.name, e.faculty_printed_name, 'Unknown Faculty') AS faculty_name,
        COALESCE(t.department, u.department, '') AS department
    FROM evaluations e
    LEFT JOIN teachers t ON e.teacher_id = t.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE e.status = 'completed'
      AND e.strengths IS NOT NULL AND TRIM(e.strengths) <> ''
      AND e.improvement_areas IS NOT NULL AND TRIM(e.improvement_areas) <> ''
      AND e.recommendations IS NOT NULL AND TRIM(e.recommendations) <> ''
    ORDER BY e.created_at DESC
";

$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$rows) {
    echo "No completed evaluations found to backfill.\n";
    exit(0);
}

$detailStmt = $db->prepare(
    "SELECT category, criterion_index, rating, comments
     FROM evaluation_details
     WHERE evaluation_id = :evaluation_id
     ORDER BY category, criterion_index"
);

$upsert = $db->prepare(
    "INSERT INTO ai_reference_evaluations (
        evaluation_id,
        faculty_name,
        department,
        subject_observed,
        observation_type,
        communications_avg,
        management_avg,
        assessment_avg,
        overall_avg,
        ratings_json,
        strengths,
        improvement_areas,
        recommendations,
        source,
        source_evaluation_id,
        reference_created_at
    ) VALUES (
        :evaluation_id,
        :faculty_name,
        :department,
        :subject_observed,
        :observation_type,
        :communications_avg,
        :management_avg,
        :assessment_avg,
        :overall_avg,
        :ratings_json,
        :strengths,
        :improvement_areas,
        :recommendations,
        :source,
        :source_evaluation_id,
        :reference_created_at
    )
    ON DUPLICATE KEY UPDATE
        faculty_name = VALUES(faculty_name),
        department = VALUES(department),
        subject_observed = VALUES(subject_observed),
        observation_type = VALUES(observation_type),
        communications_avg = VALUES(communications_avg),
        management_avg = VALUES(management_avg),
        assessment_avg = VALUES(assessment_avg),
        overall_avg = VALUES(overall_avg),
        ratings_json = VALUES(ratings_json),
        strengths = VALUES(strengths),
        improvement_areas = VALUES(improvement_areas),
        recommendations = VALUES(recommendations),
        source = VALUES(source),
        source_evaluation_id = VALUES(source_evaluation_id),
        reference_created_at = VALUES(reference_created_at)"
);

$inserted = 0;
foreach ($rows as $row) {
    $detailStmt->execute([':evaluation_id' => (int)$row['id']]);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ratings = [];
    foreach ($details as $detail) {
        $category = $detail['category'] ?? 'other';
        if (!isset($ratings[$category])) {
            $ratings[$category] = [];
        }
        $ratings[$category][] = [
            'rating' => (float)($detail['rating'] ?? 0),
            'comment' => trim((string)($detail['comments'] ?? '')),
        ];
    }

    $upsert->execute([
        ':evaluation_id' => (int)$row['id'],
        ':faculty_name' => trim((string)($row['faculty_name'] ?? '')),
        ':department' => trim((string)($row['department'] ?? '')),
        ':subject_observed' => trim((string)($row['subject_observed'] ?? '')),
        ':observation_type' => trim((string)($row['observation_type'] ?? '')),
        ':communications_avg' => (float)($row['communications_avg'] ?? 0),
        ':management_avg' => (float)($row['management_avg'] ?? 0),
        ':assessment_avg' => (float)($row['assessment_avg'] ?? 0),
        ':overall_avg' => (float)($row['overall_avg'] ?? 0),
        ':ratings_json' => json_encode($ratings, JSON_UNESCAPED_UNICODE),
        ':strengths' => trim((string)($row['strengths'] ?? '')),
        ':improvement_areas' => trim((string)($row['improvement_areas'] ?? '')),
        ':recommendations' => trim((string)($row['recommendations'] ?? '')),
        ':source' => 'database-import',
        ':source_evaluation_id' => (int)$row['id'],
        ':reference_created_at' => (string)($row['created_at'] ?? date('c')),
    ]);
    $inserted++;
}

echo "Backfilled {$inserted} completed evaluations into ai_reference_evaluations.\n";
