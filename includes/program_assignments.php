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
    $result = [];
    foreach ($programs as $p) {
        $p = trim((string)$p);
        if ($p !== '' && !in_array($p, $result, true)) {
            $result[] = $p;
        }
    }

    // Always include the coordinator's own department as part of allowed programs.
    // This prevents assigned program rows from unintentionally hiding their base department.
    if ($fallbackDepartment !== null && trim((string)$fallbackDepartment) !== '') {
        $fb = trim((string)$fallbackDepartment);
        if (!in_array($fb, $result, true)) {
            $result[] = $fb;
        }
    }

    if (!empty($result)) {
        return $result;
    }

    $deptStmt = $db->prepare("SELECT department FROM users WHERE id = :id LIMIT 1");
    $deptStmt->bindParam(':id', $evaluatorId);
    $deptStmt->execute();
    $dept = $deptStmt->fetchColumn();
    if ($dept !== false && trim((string)$dept) !== '') {
        return [trim((string)$dept)];
    }

    return [];
}

/**
 * Hard guard for coordinator department filters.
 * Returns a safe department value scoped to allowed programs.
 */
function guardCoordinatorDepartment($requestedDepartment, array $allowedDepartments, $fallbackDepartment = '') {
    $requested = trim((string)$requestedDepartment);
    $fallback = trim((string)$fallbackDepartment);
    $allowed = array_values(array_unique(array_filter(array_map(function($d) {
        return trim((string)$d);
    }, $allowedDepartments), function($d) {
        return $d !== '';
    })));

    if (!empty($allowed) && in_array($requested, $allowed, true)) {
        return $requested;
    }
    if ($fallback !== '' && in_array($fallback, $allowed, true)) {
        return $fallback;
    }
    return $allowed[0] ?? $fallback;
}
?>
