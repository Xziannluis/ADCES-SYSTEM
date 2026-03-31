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

// Get evaluation details
$details_query = "SELECT * FROM evaluation_details 
                  WHERE evaluation_id = :eval_id 
                  ORDER BY category, criterion_index";
$details_stmt = $db->prepare($details_query);
$details_stmt->bindParam(':eval_id', $_GET['eval_id']);
$details_stmt->execute();
$eval_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

$categorized_ratings = [
    'communications' => [],
    'management' => [],
    'assessment' => []
];

foreach($eval_details as $detail) {
    $categorized_ratings[$detail['category']][] = $detail;
}
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
        .report-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .report-table th, .report-table td { padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; vertical-align: top; }
        .report-table th { background: #f8f9fa; font-weight: 600; color: #2c3e50; white-space: nowrap; }
        .report-table { min-width: 1100px; }
        .back-button { display: inline-block; margin-bottom: 20px; color: #3498db; text-decoration: none; border: 1px solid #3498db; padding: 6px 12px; border-radius: 4px; }
        .back-button:hover { color: #2c3e50; transform: translateX(-5px); }
        @media (max-width: 767.98px) { .report-table { min-width: 920px; } }
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
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)
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
                <h3><?php echo htmlspecialchars($evaluation['teacher_name']); ?> - Classroom Evaluation</h3>
                <p><i class="fas fa-user-tie me-2"></i>Evaluator: <?php echo htmlspecialchars($evaluation['evaluator_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $evaluation['evaluator_role'])); ?>)</p>
                <p><i class="fas fa-calendar me-2"></i>Evaluation Date: <?php echo date('F d, Y', strtotime($evaluation['observation_date'])); ?> | <?php 
                    $time_display = $evaluation['observation_time'] ?? null;
                    if (empty($time_display) && !empty($evaluation['created_at'])) {
                        $time_display = date('h:i A', strtotime($evaluation['created_at']));
                    }
                    echo htmlspecialchars($time_display ?: 'Time not specified');
                ?></p>
                <p><i class="fas fa-book me-2"></i>Subject: <?php echo htmlspecialchars($evaluation['subject_observed'] ?? 'Not specified'); ?></p>
            </div>

            <div class="content-area">
                <?php
                $strengths = [];
                $areas_for_improvement = [];
                $recommendations = [];
                $agreements = [];
                foreach($eval_details as $detail) {
                    if (!empty($detail['comments'])) {
                        $comment = htmlspecialchars($detail['comments']);
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
                if (!empty($evaluation['strengths'])) { $strengths[] = htmlspecialchars($evaluation['strengths']); }
                if (!empty($evaluation['improvement_areas'])) { $areas_for_improvement[] = htmlspecialchars($evaluation['improvement_areas']); }
                if (!empty($evaluation['recommendations'])) { $recommendations[] = htmlspecialchars($evaluation['recommendations']); }
                if (!empty($evaluation['agreement'])) { $agreements[] = htmlspecialchars($evaluation['agreement']); }

                $rating_text = 'Needs Improvement';
                $rscore = (int) floor($evaluation['overall_avg']);
                switch ($rscore) {
                    case 5: $rating_text = 'Excellent'; break;
                    case 4: $rating_text = 'Very Satisfactory'; break;
                    case 3: $rating_text = 'Satisfactory'; break;
                    case 2: $rating_text = 'Below Satisfactory'; break;
                    default: $rating_text = 'Needs Improvement'; break;
                }
                ?>

                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Name of Teacher Observed</th>
                                <th>Subject/Class Schedule</th>
                                <th>Strength</th>
                                <th>Areas for Improvement</th>
                                <th>Recommendation/s</th>
                                <th>Agreement</th>
                                <th>Ratings</th>
                                <th class="no-print text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo date('F j, Y', strtotime($evaluation['observation_date'])); ?></td>
                                <td><?php echo htmlspecialchars($evaluation['teacher_name']); ?></td>
                                <td><?php echo htmlspecialchars($evaluation['subject_observed']); ?></td>
                                <td><?php if(!empty($strengths)): ?><ul><?php foreach($strengths as $s){ ?><li><?php echo $s; ?></li><?php } ?></ul><?php else: ?><em>No specific strengths identified.</em><?php endif; ?></td>
                                <td><?php if(!empty($areas_for_improvement)): ?><ul><?php foreach($areas_for_improvement as $a){ ?><li><?php echo $a; ?></li><?php } ?></ul><?php else: ?><em>No specific areas for improvement identified.</em><?php endif; ?></td>
                                <td><?php if(!empty($recommendations)): ?><ul><?php foreach($recommendations as $r){ ?><li><?php echo $r; ?></li><?php } ?></ul><?php else: ?><em>No specific recommendations provided.</em><?php endif; ?></td>
                                <td><?php if(!empty($agreements)): ?><ul><?php foreach($agreements as $ag){ ?><li><?php echo $ag; ?></li><?php } ?></ul><?php else: ?><em>No specific agreements recorded.</em><?php endif; ?></td>
                                <td style="white-space:nowrap;"><?php echo htmlspecialchars(number_format($evaluation['overall_avg'],1)) . ' ' . $rating_text; ?></td>
                                <td class="no-print text-center" style="white-space:nowrap; vertical-align:top;">
                                    <a href="<?php echo (($evaluation['evaluation_form_type'] ?? 'iso') === 'peac') ? 'view_evaluation_peac.php' : 'view_evaluation.php'; ?>?id=<?php echo (int)$_GET['eval_id']; ?>" class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                    <a href="<?php echo (($evaluation['evaluation_form_type'] ?? 'iso') === 'peac') ? 'print_evaluation_form_peac.php' : 'print_evaluation_form.php'; ?>?id=<?php echo (int)$_GET['eval_id']; ?>&auto_print=1" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-print me-1"></i> Print
                                    </a>
                                </td>
                            </tr>
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
