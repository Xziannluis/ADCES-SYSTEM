<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$db = (new Database())->getConnection();

// Find the evaluator's teacher record
$teacher_query = "SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1";
$teacher_stmt = $db->prepare($teacher_query);
$teacher_stmt->bindParam(':user_id', $_SESSION['user_id']);
$teacher_stmt->execute();
$my_teacher = $teacher_stmt->fetch(PDO::FETCH_ASSOC);

if(!$my_teacher || !isset($_GET['eval_id'])) {
    $_SESSION['error'] = "Evaluation not found.";
    header("Location: my_evaluations.php");
    exit();
}

$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role, t.name as teacher_name
          FROM evaluations e
          JOIN users u ON e.evaluator_id = u.id
          JOIN teachers t ON e.teacher_id = t.id
          WHERE e.id = :eval_id AND e.teacher_id = :teacher_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':eval_id', $_GET['eval_id']);
$stmt->bindParam(':teacher_id', $my_teacher['id']);
$stmt->execute();

if($stmt->rowCount() === 0) {
    $_SESSION['error'] = "Evaluation not found or you don't have access.";
    header("Location: my_evaluations.php");
    exit();
}

$evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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

$anchor_date = $normalize_schedule_date($evaluation['observation_date'] ?? '');
$anchor_time = $normalize_schedule_time($evaluation['observation_time'] ?? '');
$anchor_subject = $normalize_subject_slot($evaluation['subject_observed'] ?? '');
$anchor_academic_year = (string)($evaluation['academic_year'] ?? '');
$anchor_semester = (string)($evaluation['semester'] ?? '');
$anchor_department = (string)($evaluation['department'] ?? '');

$schedule_query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role, u.department as evaluator_department,
                          t.name as teacher_name, t.evaluation_schedule, t.evaluation_schedule_end
                   FROM evaluations e
                   JOIN users u ON e.evaluator_id = u.id
                   JOIN teachers t ON e.teacher_id = t.id
                   WHERE e.teacher_id = :teacher_id
                     AND e.status = 'completed'
                      AND DATE(e.observation_date) = :observation_date
                      AND COALESCE(e.academic_year, '') = :academic_year
                      AND COALESCE(e.semester, '') = :semester
                      AND COALESCE(e.department, '') = :department
                    ORDER BY e.created_at ASC, e.id ASC";
$schedule_stmt = $db->prepare($schedule_query);
$schedule_stmt->execute([
    ':teacher_id' => (int)$my_teacher['id'],
    ':observation_date' => $anchor_date,
    ':academic_year' => $anchor_academic_year,
    ':semester' => $anchor_semester,
    ':department' => $anchor_department,
]);
$schedule_candidates = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);

$all_evaluations = [];
foreach ($schedule_candidates as $candidate) {
    $candidate_time = $normalize_schedule_time($candidate['observation_time'] ?? '');
    $candidate_subject = $normalize_subject_slot($candidate['subject_observed'] ?? '');
    if ($candidate_time === $anchor_time && ($anchor_subject === '' || $candidate_subject === $anchor_subject)) {
        $all_evaluations[] = $candidate;
    }
}
if (empty($all_evaluations)) {
    $all_evaluations[] = $evaluation;
}

$all_evaluations_data = [];
foreach ($all_evaluations as $eval_row) {
    $details_query = "SELECT * FROM evaluation_details
                      WHERE evaluation_id = :eval_id
                      ORDER BY category, criterion_index";
    $details_stmt = $db->prepare($details_query);
    $details_stmt->bindValue(':eval_id', (int)$eval_row['id'], PDO::PARAM_INT);
    $details_stmt->execute();
    $all_evaluations_data[] = [
        'eval' => $eval_row,
        'details' => $details_stmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

$teacher_name = $all_evaluations[0]['teacher_name'] ?? $evaluation['teacher_name'];
$observation_date = $all_evaluations[0]['observation_date'] ?? $evaluation['observation_date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View My Evaluation - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .content-area { background: white; border-radius: 12px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .eval-header { background: linear-gradient(135deg, #2c3e50, #3498db); color: white; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .eval-header h3 { margin: 0; font-weight: 700; }
        .eval-header p { margin: 8px 0 0 0; opacity: 0.9; }
        .report-summary { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 10px; padding: 16px; }
        .report-item { margin-bottom: 10px; color: #2c3e50; }
        .report-item:last-child { margin-bottom: 0; }
        .report-item strong { display: inline-block; min-width: 190px; }
        .observer-section { margin-bottom: 20px; }
        .observer-section h5 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .rating-box { background-color: #e3f2fd !important; border-left: 4px solid #3498db !important; }
        .comment-block h6 { font-weight: 600; margin-bottom: 10px; }
        .comment-list { list-style-type: disc; padding-left: 20px; }
        .comment-list li { margin-bottom: 8px; line-height: 1.6; }
        .back-button { display: inline-block; margin-bottom: 20px; color: #3498db; text-decoration: none; border: 1px solid #3498db; padding: 6px 12px; border-radius: 4px; }
        .back-button:hover { color: #2c3e50; transform: translateX(-5px); }
        @media (max-width: 767.98px) { .report-item strong { min-width: 100%; margin-bottom: 4px; } }
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

            <a href="my_evaluations.php" class="back-button no-print">
                <i class="fas fa-arrow-left me-2"></i>Back to My Evaluations
            </a>

            <div class="eval-header">
                <h3><?php echo h($teacher_name); ?> - Classroom Evaluation</h3>
                <p><i class="fas fa-user-tie me-2"></i>Observer Evaluation</p>
                <p><i class="fas fa-calendar me-2"></i>Evaluation Date: <?php echo date('F d, Y', strtotime($observation_date)); ?> | <?php
                    $time_display = $evaluation['observation_time'] ?? null;
                    if (empty($time_display) && !empty($evaluation['created_at'])) {
                        $time_display = date('h:i A', strtotime($evaluation['created_at']));
                    }
                    echo h($time_display ?: 'Time not specified');
                ?></p>
                <p><i class="fas fa-book me-2"></i>Subject: <?php echo h($evaluation['subject_observed'] ?? 'Not specified'); ?></p>
            </div>

            <div class="content-area">
                <?php
                    $subject_observed = trim((string)($all_evaluations[0]['subject_observed'] ?? ''));
                    $schedule_text = h($subject_observed !== '' ? $subject_observed : 'N/A');

                    $start_raw = $all_evaluations[0]['observation_start_time'] ?? '';
                    $end_raw = $all_evaluations[0]['observation_end_time'] ?? '';

                    if (empty($start_raw) && !empty($all_evaluations[0]['observation_time'])) {
                        $start_raw = $all_evaluations[0]['observation_time'];
                    }
                    if (empty($start_raw) && !empty($all_evaluations[0]['evaluation_schedule'])) {
                        $start_raw = $all_evaluations[0]['evaluation_schedule'];
                    }
                    if (empty($end_raw) && !empty($all_evaluations[0]['evaluation_schedule_end'])) {
                        $end_raw = $all_evaluations[0]['evaluation_schedule_end'];
                    }

                    if (!empty($start_raw) && !empty($end_raw)) {
                        $start_time = date('g:i', strtotime($start_raw));
                        $end_time = date('g:i A', strtotime($end_raw));
                        $schedule_text .= ' (' . h($start_time . ' - ' . $end_time) . ')';
                    } elseif (!empty($start_raw)) {
                        $time = date('g:i A', strtotime($start_raw));
                        $schedule_text .= ' (' . h($time) . ')';
                    }

                    $schedule_departments = [];
                    foreach ($all_evaluations_data as $entry) {
                        $schedule_department = trim((string)($entry['eval']['department'] ?? ''));
                        if ($schedule_department !== '') {
                            $schedule_departments[] = $schedule_department;
                        }
                    }
                    $schedule_departments = array_values(array_unique($schedule_departments));
                ?>

                <div class="report-summary">
                    <div class="report-item"><strong>Date:</strong> <?php echo date('F j, Y', strtotime($observation_date)); ?></div>
                    <div class="report-item"><strong>Department:</strong> <?php echo !empty($schedule_departments) ? h(implode(', ', $schedule_departments)) : 'N/A'; ?></div>
                    <div class="report-item"><strong>Subject/Class Schedule:</strong> <?php echo $schedule_text; ?></div>
                </div>

                <div class="comments-section mt-5">
                    <?php
                    $observer_number = 1;
                    foreach ($all_evaluations_data as $eval_data):
                        $eval = $eval_data['eval'];
                        $details = $eval_data['details'];

                        $strengths = [];
                        $areas_for_improvement = [];
                        $recommendations = [];
                        $agreements = [];

                        foreach ($details as $detail) {
                            if (!empty($detail['comments'])) {
                                $comment = (string)$detail['comments'];
                                if (stripos($comment, 'strength') !== false || stripos($comment, 'good') !== false || stripos($comment, 'excellent') !== false) {
                                    $strengths[] = $comment;
                                } elseif (stripos($comment, 'improve') !== false || stripos($comment, 'better') !== false || stripos($comment, 'suggestion') !== false) {
                                    $areas_for_improvement[] = $comment;
                                } elseif (stripos($comment, 'recommend') !== false) {
                                    $recommendations[] = $comment;
                                } elseif (stripos($comment, 'agree') !== false || stripos($comment, 'acknowledge') !== false) {
                                    $agreements[] = $comment;
                                } else {
                                    $strengths[] = $comment;
                                }
                            }
                        }

                        if (!empty($eval['strengths'])) $strengths[] = $eval['strengths'];
                        if (!empty($eval['improvement_areas'])) $areas_for_improvement[] = $eval['improvement_areas'];
                        if (!empty($eval['recommendations'])) $recommendations[] = $eval['recommendations'];
                        if (!empty($eval['agreement'])) $agreements[] = $eval['agreement'];
                    ?>
                    <div class="observer-section mb-5 p-4" style="background: #f8f9fa; border-left: 4px solid #3498db; border-radius: 8px;">
                        <h5 class="mb-4">
                            <i class="fas fa-user-circle me-2"></i>
                            <strong>OBSERVER <?php echo $observer_number; ?></strong>
                        </h5>

                        <?php if (!empty($eval['overall_avg'])): ?>
                        <div class="rating-box mb-4 p-3" style="background: white; border-radius: 6px; border: 1px solid #dee2e6;">
                            <strong>Overall Rating:</strong>
                            <span style="font-size: 1.2rem; color: #3498db; font-weight: bold;">
                                <?php
                                $rscore = (float)$eval['overall_avg'];
                                $rating_text = 'Needs Improvement';
                                if ($rscore >= 4.6) {
                                    $rating_text = 'Excellent';
                                } elseif ($rscore >= 3.6) {
                                    $rating_text = 'Very Satisfactory';
                                } elseif ($rscore >= 2.6) {
                                    $rating_text = 'Satisfactory';
                                } elseif ($rscore >= 1.6) {
                                    $rating_text = 'Below Satisfactory';
                                }
                                echo h(number_format((float)$eval['overall_avg'], 1) . ' - ' . $rating_text);
                                ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <div class="comment-block mb-4">
                            <h6 class="text-success mb-2"><i class="fas fa-star me-2"></i>Strengths</h6>
                            <?php if (!empty($strengths)): ?>
                                <ul class="comment-list ms-3">
                                    <?php foreach ($strengths as $s): ?>
                                        <li><?php echo h($s); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted ms-3"><em>No specific strengths identified.</em></p>
                            <?php endif; ?>
                        </div>

                        <div class="comment-block mb-4">
                            <h6 class="text-warning mb-2"><i class="fas fa-lightbulb me-2"></i>Areas for Improvement</h6>
                            <?php if (!empty($areas_for_improvement)): ?>
                                <ul class="comment-list ms-3">
                                    <?php foreach ($areas_for_improvement as $a): ?>
                                        <li><?php echo h($a); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted ms-3"><em>No specific areas for improvement identified.</em></p>
                            <?php endif; ?>
                        </div>

                        <div class="comment-block mb-4">
                            <h6 class="text-info mb-2"><i class="fas fa-check me-2"></i>Recommendations</h6>
                            <?php if (!empty($recommendations)): ?>
                                <ul class="comment-list ms-3">
                                    <?php foreach ($recommendations as $r): ?>
                                        <li><?php echo h($r); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted ms-3"><em>No specific recommendations provided.</em></p>
                            <?php endif; ?>
                        </div>

                        <div class="comment-block mb-4">
                            <h6 class="text-primary mb-2"><i class="fas fa-handshake me-2"></i>Agreements</h6>
                            <?php if (!empty($agreements)): ?>
                                <ul class="comment-list ms-3">
                                    <?php foreach ($agreements as $ag): ?>
                                        <li><?php echo h($ag); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted ms-3"><em>No specific agreements recorded.</em></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                    $observer_number++;
                    endforeach;
                    ?>
                </div>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>

