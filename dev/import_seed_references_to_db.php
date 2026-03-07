<?php
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed\n");
    exit(1);
}

$seedPath = __DIR__ . '/../ai_service/reference_evaluations.jsonl';
if (!file_exists($seedPath)) {
    fwrite(STDERR, "Missing reference_evaluations.jsonl\n");
    exit(2);
}

$lines = file($seedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
if (!$lines) {
    echo "No seed references found.\n";
    exit(0);
}

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

$seedBaseId = 1000000;
$inserted = 0;
foreach ($lines as $index => $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }

    $averages = $row['averages'] ?? [];
    $ratings = $row['ratings'] ?? [];
    $source = trim((string)($row['source'] ?? 'seed-generated')) ?: 'seed-generated';
    $createdAt = trim((string)($row['created_at'] ?? date('c')));

    $upsert->execute([
        ':evaluation_id' => null,
        ':faculty_name' => trim((string)($row['faculty_name'] ?? '')),
        ':department' => trim((string)($row['department'] ?? '')),
        ':subject_observed' => trim((string)($row['subject_observed'] ?? '')),
        ':observation_type' => trim((string)($row['observation_type'] ?? '')),
        ':communications_avg' => (float)($averages['communications'] ?? 0),
        ':management_avg' => (float)($averages['management'] ?? 0),
        ':assessment_avg' => (float)($averages['assessment'] ?? 0),
        ':overall_avg' => (float)($averages['overall'] ?? 0),
        ':ratings_json' => json_encode($ratings, JSON_UNESCAPED_UNICODE),
        ':strengths' => trim((string)($row['strengths'] ?? '')),
        ':improvement_areas' => trim((string)($row['improvement_areas'] ?? '')),
        ':recommendations' => trim((string)($row['recommendations'] ?? '')),
        ':source' => $source,
        ':source_evaluation_id' => null,
        ':reference_created_at' => $createdAt,
    ]);
    $inserted++;
}

echo "Imported {$inserted} seed references into ai_reference_evaluations.\n";
