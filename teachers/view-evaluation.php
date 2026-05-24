<?php
session_start();

// Check if teacher is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$db = (new Database())->getConnection();

// Get all evaluations for this teacher grouped by observation date
if(!isset($_GET['date'])) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: dashboard.php");
    exit();
}

$obs_date = trim($_GET['date']);

// Get all evaluations for this teacher on this date
$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role, t.name as teacher_name, 
                 t.evaluation_schedule, t.evaluation_schedule_end
          FROM evaluations e
          JOIN users u ON e.evaluator_id = u.id
          JOIN teachers t ON e.teacher_id = t.id
          WHERE DATE(e.observation_date) = :obs_date AND e.teacher_id = :teacher_id
          ORDER BY e.created_at DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':obs_date', $obs_date);
$stmt->bindParam(':teacher_id', $_SESSION['teacher_id']);
$stmt->execute();

$all_evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(empty($all_evaluations)) {
    $_SESSION['error'] = "No evaluations found for this date.";
    header("Location: dashboard.php");
    exit();
}

// Get teacher name (same for all rows)
$teacher_name = $all_evaluations[0]['teacher_name'];
$observation_date = $all_evaluations[0]['observation_date'];

// Collect all details and comments from all evaluators
$all_eval_details = [];
$all_evaluations_data = [];

foreach ($all_evaluations as $eval) {
    $details_query = "SELECT * FROM evaluation_details 
                      WHERE evaluation_id = :eval_id 
                      ORDER BY category, criterion_index";
    $details_stmt = $db->prepare($details_query);
    $details_stmt->bindParam(':eval_id', $eval['id']);
    $details_stmt->execute();
    $eval_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $all_evaluations_data[] = [
        'eval' => $eval,
        'details' => $eval_details
    ];
    $all_eval_details = array_merge($all_eval_details, $eval_details);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Evaluation - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .eval-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .eval-header h3 { margin: 0; font-weight: 700; }
        .eval-header p { margin: 8px 0 0 0; opacity: 0.9; }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .report-table th, .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        .report-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            white-space: nowrap;
        }
        .observer-section {
            margin-bottom: 20px;
        }
        .observer-section h5 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .rating-box {
            background-color: #e3f2fd !important;
            border-left: 4px solid #3498db !important;
        }
        .comment-block h6 {
            font-weight: 600;
            margin-bottom: 10px;
        }
        .comment-list {
            list-style-type: disc;
            padding-left: 20px;
        }
        .comment-list li {
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .back-button {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            border: 1px solid #3498db;
            padding: 6px 12px;
            border-radius: 4px;
        }
        .back-button:hover { color: #2c3e50; transform: translateX(-5px); }
        @media (max-width: 767.98px) {
            .report-table { min-width: 920px; }
        }
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="teacherMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (Teacher)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

                <!-- Back Button -->
                <a href="dashboard.php" class="back-button no-print">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>

                <!-- Header -->
                <div class="eval-header">
                    <h3><?php echo htmlspecialchars($teacher_name); ?> - Classroom Observation</h3>
                    <p><i class="fas fa-calendar me-2"></i>Observation Date: <?php echo date('F d, Y', strtotime($observation_date)); ?></p>
                </div>

                <!-- Evaluation Content -->
                <div class="content-area">
                    <!-- Single merged row table -->
                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Subject/Class Schedule</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo date('F j, Y', strtotime($observation_date)); ?></td>
                                    <td>
                                        <?php 
                                        $subject_observed = trim((string)($all_evaluations[0]['subject_observed'] ?? ''));
                                        $schedule_text = htmlspecialchars($subject_observed !== '' ? $subject_observed : 'N/A');
                                        
                                        // Prefer explicit observation range, then teacher schedule range, then single time fallback.
                                        $start_raw = $all_evaluations[0]['observation_start_time'] ?? '';
                                        $end_raw = $all_evaluations[0]['observation_end_time'] ?? '';

                                        // If start range isn't present, use single observation_time as start.
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
                                            $schedule_text .= ' (' . $start_time . ' - ' . $end_time . ')';
                                        } elseif (!empty($start_raw)) {
                                            $time = date('g:i A', strtotime($start_raw));
                                            $schedule_text .= ' (' . $time . ')';
                                        }
                                        
                                        echo $schedule_text;
                                        ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Comments Section - Display Automatically -->
                    <div class="comments-section mt-5">
                        <?php
                        $observer_number = 1;
                        foreach ($all_evaluations_data as $eval_data):
                            $eval = $eval_data['eval'];
                            $details = $eval_data['details'];
                            
                            // Collect comments for this observer
                            $strengths = [];
                            $areas_for_improvement = [];
                            $recommendations = [];
                            $agreements = [];
                            
                            foreach($details as $detail) {
                                if (!empty($detail['comments'])) {
                                    $comment = $detail['comments'];
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
                            
                            <!-- Ratings -->
                            <?php if (!empty($eval['overall_avg'])): ?>
                            <div class="rating-box mb-4 p-3" style="background: white; border-radius: 6px; border: 1px solid #dee2e6;">
                                <strong>Overall Rating:</strong> 
                                <span style="font-size: 1.2rem; color: #3498db; font-weight: bold;">
                                    <?php 
                                    $rscore = (int) floor($eval['overall_avg']);
                                    $rating_text = 'Needs Improvement';
                                    switch ($rscore) {
                                        case 5: $rating_text = 'Excellent'; break;
                                        case 4: $rating_text = 'Very Satisfactory'; break;
                                        case 3: $rating_text = 'Satisfactory'; break;
                                        case 2: $rating_text = 'Below Satisfactory'; break;
                                        default: $rating_text = 'Needs Improvement'; break;
                                    }
                                    echo htmlspecialchars(number_format($eval['overall_avg'], 1)) . ' - ' . $rating_text;
                                    ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Strengths -->
                            <div class="comment-block mb-4">
                                <h6 class="text-success mb-2"><i class="fas fa-star me-2"></i>Strengths</h6>
                                <?php if (!empty($strengths)): ?>
                                    <ul class="comment-list ms-3">
                                        <?php foreach($strengths as $s): ?>
                                            <li><?php echo htmlspecialchars($s); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted ms-3"><em>No specific strengths identified.</em></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Areas for Improvement -->
                            <div class="comment-block mb-4">
                                <h6 class="text-warning mb-2"><i class="fas fa-lightbulb me-2"></i>Areas for Improvement</h6>
                                <?php if (!empty($areas_for_improvement)): ?>
                                    <ul class="comment-list ms-3">
                                        <?php foreach($areas_for_improvement as $a): ?>
                                            <li><?php echo htmlspecialchars($a); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted ms-3"><em>No specific areas for improvement identified.</em></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Recommendations -->
                            <div class="comment-block mb-4">
                                <h6 class="text-info mb-2"><i class="fas fa-check me-2"></i>Recommendations</h6>
                                <?php if (!empty($recommendations)): ?>
                                    <ul class="comment-list ms-3">
                                        <?php foreach($recommendations as $r): ?>
                                            <li><?php echo htmlspecialchars($r); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted ms-3"><em>No specific recommendations provided.</em></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Agreements -->
                            <div class="comment-block mb-4">
                                <h6 class="text-primary mb-2"><i class="fas fa-handshake me-2"></i>Agreements</h6>
                                <?php if (!empty($agreements)): ?>
                                    <ul class="comment-list ms-3">
                                        <?php foreach($agreements as $ag): ?>
                                            <li><?php echo htmlspecialchars($ag); ?></li>
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

                    <!-- end report-style output -->
                </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

</body>
</html>
