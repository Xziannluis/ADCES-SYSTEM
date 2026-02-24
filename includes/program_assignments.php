<?php
function columnExists($db, $table, $column) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
        $stmt->bindParam(':column', $column);
        $stmt->execute();
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('columnExists check failed: ' . $e->getMessage());
        return false;
    }
}

function getEvaluatorAssignedPrograms($db, $evaluatorId) {
    if (!columnExists($db, 'evaluator_assignments', 'program')) {
        return [];
    }

    $stmt = $db->prepare(
        "SELECT DISTINCT program FROM evaluator_assignments WHERE evaluator_id = :evaluator_id AND program IS NOT NULL AND program <> ''"
    );
    $stmt->bindParam(':evaluator_id', $evaluatorId);
    $stmt->execute();
    $programs = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    return array_values(array_filter(array_map('trim', $programs), function ($p) {
        return $p !== '';
    }));
}

function resolveEvaluatorPrograms($db, $evaluatorId, $fallbackDepartment = null) {
    $programs = getEvaluatorAssignedPrograms($db, $evaluatorId);
    if (!empty($programs)) {
        return $programs;
    }

    if (!columnExists($db, 'evaluator_assignments', 'program')) {
        if ($fallbackDepartment !== null && trim((string)$fallbackDepartment) !== '') {
            return [trim((string)$fallbackDepartment)];
        }

        $deptStmt = $db->prepare("SELECT department FROM users WHERE id = :id LIMIT 1");
        $deptStmt->bindParam(':id', $evaluatorId);
        $deptStmt->execute();
        $dept = $deptStmt->fetchColumn();
        if ($dept !== false && trim((string)$dept) !== '') {
            return [trim((string)$dept)];
        }
    }

    return [];
}
?>