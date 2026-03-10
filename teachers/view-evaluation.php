<?php
session_start();

// Check if teacher is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$db = (new Database())->getConnection();

// Get evaluation details
if(!isset($_GET['eval_id'])) {
    $_SESSION['error'] = "Evaluation not found.";
    header("Location: dashboard.php");
    exit();
}

$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role, t.name as teacher_name
          FROM evaluations e
          JOIN users u ON e.evaluator_id = u.id
          JOIN teachers t ON e.teacher_id = t.id
          WHERE e.id = :eval_id AND e.teacher_id = :teacher_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':eval_id', $_GET['eval_id']);
$stmt->bindParam(':teacher_id', $_SESSION['teacher_id']);
$stmt->execute();

if($stmt->rowCount() === 0) {
    $_SESSION['error'] = "Evaluation not found or you don't have access.";
    header("Location: dashboard.php");
    exit();
}

$evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

// Get evaluation details (ratings for each category)
$details_query = "SELECT * FROM evaluation_details 
                  WHERE evaluation_id = :eval_id 
                  ORDER BY category, criterion_index";
$details_stmt = $db->prepare($details_query);
$details_stmt->bindParam(':eval_id', $_GET['eval_id']);
$details_stmt->execute();
$eval_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize evaluation details by category
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
    <title>View Evaluation - AI Classroom Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: var(--primary) !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.3rem;
        }

        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .eval-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .eval-header h3 {
            margin: 0;
            font-weight: 700;
        }

        .eval-header p {
            margin: 8px 0 0 0;
            opacity: 0.9;
        }

        .section-title {
            color: var(--primary);
            font-weight: 700;
            border-bottom: 3px solid var(--secondary);
            padding-bottom: 10px;
            margin-top: 25px;
            margin-bottom: 15px;
        }

        .rating-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .rating-table th,
        .rating-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .rating-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--primary);
        }

        .rating-display {
            background: var(--secondary);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
        }

        .score-box {
            background: linear-gradient(135deg, var(--secondary), #5dade2);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin: 15px 0;
        }

        .score-box h5 {
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .score-box .score-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0 0 0;
        }

        .back-button {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s;
            border: 1px solid var(--secondary);
            padding: 6px 12px;
            border-radius: 4px;
        }

        .back-button:hover {
            color: var(--primary);
            transform: translateX(-5px);
        }

        .print-button {
            background: var(--primary);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .print-button:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }

        .comment-box {
            background: #f8f9fa;
            padding: 12px;
            border-left: 4px solid var(--secondary);
            border-radius: 4px;
            font-size: 0.9rem;
            margin-top: 8px;
        }

        .table-responsive {
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .report-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--primary);
        }

        @media (max-width: 991.98px) {
            .content-area {
                margin: 16px 0;
                padding: 22px;
            }

            .eval-header {
                padding: 20px;
            }
        }

        @media (max-width: 767.98px) {
            .container-fluid {
                padding-left: 12px;
                padding-right: 12px;
            }

            .col-md-10.offset-md-1 {
                width: 100%;
                margin-left: 0;
            }

            .content-area,
            .eval-header {
                margin: 16px 0;
                padding: 16px;
                border-radius: 10px;
            }

            .report-table {
                min-width: 920px;
            }

            .back-button,
            .print-button {
                width: 100%;
                text-align: center;
            }
        }

       
        
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>Evaluation Details
            </a>
            <div class="ms-auto">
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="teacherMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['name']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                        <li>
                            <a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="change-password.php">
                                <i class="fas fa-key me-2"></i>Change Password
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="../auth/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-10 offset-md-1">
                <!-- Back Button -->
                <a href="dashboard.php" class="back-button no-print">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>

                <!-- Header -->
                <div class="eval-header">
                    <h3><?php echo htmlspecialchars($evaluation['teacher_name']); ?> - Classroom Evaluation</h3>
                    <p><i class="fas fa-user-tie me-2"></i>Evaluator: <?php echo htmlspecialchars($evaluation['evaluator_name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $evaluation['evaluator_role'])); ?>)</p>
                    <p><i class="fas fa-calendar me-2"></i>Evaluation Date: <?php echo date('F d, Y', strtotime($evaluation['observation_date'])); ?> | <?php echo htmlspecialchars($evaluation['observation_time'] ?? 'Time not specified'); ?></p>
                    <p><i class="fas fa-book me-2"></i>Subject: <?php echo htmlspecialchars($evaluation['subject_observed'] ?? 'Not specified'); ?></p>
                </div>

               
                <!-- Evaluation Content -->
                <div class="content-area">
                    <?php
                    // build comment aggregates exactly like report view
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
                    if (!empty($evaluation['strengths'])) {
                        $strengths[] = htmlspecialchars($evaluation['strengths']);
                    }
                    if (!empty($evaluation['improvement_areas'])) {
                        $areas_for_improvement[] = htmlspecialchars($evaluation['improvement_areas']);
                    }
                    if (!empty($evaluation['recommendations'])) {
                        $recommendations[] = htmlspecialchars($evaluation['recommendations']);
                    }

                    // compute rating text using same rules as reports
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

                    <!-- single-row report table -->
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
                                    <td><?php echo htmlspecialchars(number_format($evaluation['overall_avg'],1)) . ' ' . $rating_text; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- end report-style output -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
