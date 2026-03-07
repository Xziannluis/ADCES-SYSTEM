<?php
require_once __DIR__ . '/../../config/database.php';

$db = (new Database())->getConnection();

$db->exec("ALTER TABLE ai_reference_evaluations DROP FOREIGN KEY fk_ai_reference_evaluations_evaluation");
$db->exec("ALTER TABLE ai_reference_evaluations MODIFY evaluation_id INT NULL");
$db->exec("ALTER TABLE ai_reference_evaluations ADD CONSTRAINT fk_ai_reference_evaluations_evaluation FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE");

echo "ai_reference_evaluations foreign key relaxed for seed references.\n";
