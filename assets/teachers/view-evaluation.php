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

        @media print {
            .no-print {
                display: none;
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

                <!-- Print Button -->
                <div class="text-end mb-3 no-print">
                    <button onclick="window.print()" class="print-button">
                        <i class="fas fa-print me-2"></i>Print Evaluation
                    </button>
                </div>

                <!-- Evaluation Content -->
                <div class="content-area">
                    <!-- COMMUNICATIONS SECTION -->
                    <?php if(count($categorized_ratings['communications']) > 0): ?>
                    <div class="section-title">
                        <i class="fas fa-comments me-2"></i>COMMUNICATIONS
                    </div>
                    <table class="rating-table">
                        <thead>
                            <tr>
                                <th>Criteria</th>
                                <th style="width: 15%;">Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $comm_total = 0;
                            $comm_count = 0;
                            foreach($categorized_ratings['communications'] as $detail): 
                                $comm_total += $detail['rating'];
                                $comm_count++;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($detail['criterion_text']); ?></strong>
                                    <?php if(!empty($detail['comments'])): ?>
                                    <div class="comment-box">
                                        <i class="fas fa-quote-left me-1"></i><?php echo nl2br(htmlspecialchars($detail['comments'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="rating-display"><?php echo htmlspecialchars($detail['rating']); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if($comm_count > 0): ?>
                    <div class="score-box">
                        <h5>COMMUNICATIONS Average Score</h5>
                        <div class="score-value"><?php echo number_format($comm_total / $comm_count, 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- MANAGEMENT SECTION -->
                    <?php if(count($categorized_ratings['management']) > 0): ?>
                    <div class="section-title">
                        <i class="fas fa-sitemap me-2"></i>MANAGEMENT / COURSE DESIGN
                    </div>
                    <table class="rating-table">
                        <thead>
                            <tr>
                                <th>Criteria</th>
                                <th style="width: 15%;">Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $mgmt_total = 0;
                            $mgmt_count = 0;
                            foreach($categorized_ratings['management'] as $detail): 
                                $mgmt_total += $detail['rating'];
                                $mgmt_count++;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($detail['criterion_text']); ?></strong>
                                    <?php if(!empty($detail['comments'])): ?>
                                    <div class="comment-box">
                                        <i class="fas fa-quote-left me-1"></i><?php echo nl2br(htmlspecialchars($detail['comments'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="rating-display"><?php echo htmlspecialchars($detail['rating']); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if($mgmt_count > 0): ?>
                    <div class="score-box">
                        <h5>MANAGEMENT Average Score</h5>
                        <div class="score-value"><?php echo number_format($mgmt_total / $mgmt_count, 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- ASSESSMENT SECTION -->
                    <?php if(count($categorized_ratings['assessment']) > 0): ?>
                    <div class="section-title">
                        <i class="fas fa-tasks me-2"></i>ASSESSMENT / TESTING
                    </div>
                    <table class="rating-table">
                        <thead>
                            <tr>
                                <th>Criteria</th>
                                <th style="width: 15%;">Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $assess_total = 0;
                            $assess_count = 0;
                            foreach($categorized_ratings['assessment'] as $detail): 
                                $assess_total += $detail['rating'];
                                $assess_count++;
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($detail['criterion_text']); ?></strong>
                                    <?php if(!empty($detail['comments'])): ?>
                                    <div class="comment-box">
                                        <i class="fas fa-quote-left me-1"></i><?php echo nl2br(htmlspecialchars($detail['comments'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="rating-display"><?php echo htmlspecialchars($detail['rating']); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if($assess_count > 0): ?>
                    <div class="score-box">
                        <h5>ASSESSMENT Average Score</h5>
                        <div class="score-value"><?php echo number_format($assess_total / $assess_count, 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Overall Average -->
                    <?php 
                    $overall_avg = 0;
                    $total_count = $comm_count + $mgmt_count + $assess_count;
                    if($total_count > 0) {
                        $overall_avg = ($comm_total + $mgmt_total + $assess_total) / $total_count;
                    }
                    ?>
                    <div style="background: linear-gradient(135deg, var(--success), #5dbe5d); color: white; padding: 30px; border-radius: 12px; text-align: center; margin-top: 30px;">
                        <h5 style="margin: 0; opacity: 0.9;">OVERALL EVALUATION SCORE</h5>
                        <div style="font-size: 3rem; font-weight: 700; margin: 15px 0 0 0;">
                            <?php echo number_format($overall_avg, 2); ?>
                        </div>
                    </div>

                    <!-- Qualitative Data Section -->
                    <?php if(!empty($evaluation['strengths']) || !empty($evaluation['improvement_areas']) || !empty($evaluation['recommendations'])): ?>
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-top: 30px; border-left: 4px solid var(--secondary);">
                        <?php if(!empty($evaluation['strengths'])): ?>
                        <h6 style="color: var(--primary); font-weight: 600; margin-bottom: 10px; margin-top: 0;">
                            <i class="fas fa-star me-2"></i>Strengths
                        </h6>
                        <p style="margin-bottom: 15px;"><?php echo nl2br(htmlspecialchars($evaluation['strengths'])); ?></p>
                        <?php endif; ?>

                        <?php if(!empty($evaluation['improvement_areas'])): ?>
                        <h6 style="color: var(--primary); font-weight: 600; margin-bottom: 10px;">
                            <i class="fas fa-lightbulb me-2"></i>Areas for Improvement
                        </h6>
                        <p style="margin-bottom: 15px;"><?php echo nl2br(htmlspecialchars($evaluation['improvement_areas'])); ?></p>
                        <?php endif; ?>

                        <?php if(!empty($evaluation['recommendations'])): ?>
                        <h6 style="color: var(--primary); font-weight: 600; margin-bottom: 10px;">
                            <i class="fas fa-tasks me-2"></i>Recommendations
                        </h6>
                        <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($evaluation['recommendations'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

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

        @media print {
            .no-print {
                display: none;
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
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['name']); ?>
                </span>
                <a class="nav-link d-inline-block" href="../auth/logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
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
                    <p><i class="fas fa-calendar me-2"></i>Evaluation Date: <?php echo date('F d, Y h:i A', strtotime($evaluation['created_at'])); ?></p>
                </div>

                <!-- Print Button -->
                <div class="text-end mb-3 no-print">
                    <button onclick="window.print()" class="print-button">
                        <i class="fas fa-print me-2"></i>Print Evaluation
                    </button>
                </div>

                <!-- Evaluation Content -->
                <div class="content-area">
                    <?php if(isset($eval_data['part1'])): ?>
                    <!-- PART 1: Content Knowledge -->
                    <div class="section-title">
                        <i class="fas fa-book me-2"></i>PART 1: Content Knowledge
                    </div>
                    <table class="rating-table">
                        <thead>
                            <tr>
                                <th>Criteria</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($eval_data['part1'] as $criterion => $rating): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($criterion); ?></td>
                                <td>
                                    <span class="rating-display"><?php echo htmlspecialchars($rating); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if(isset($eval_data['part1_average'])): ?>
                    <div class="score-box">
                        <h5>PART 1 Average Score</h5>
                        <div class="score-value"><?php echo number_format($eval_data['part1_average'], 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if(isset($eval_data['part2'])): ?>
                    <!-- PART 2: Teaching Methodology -->
                    <div class="section-title">
                        <i class="fas fa-chalkboard-teacher me-2"></i>PART 2: Teaching Methodology
                    </div>
                    <table class="rating-table">
                        <thead>
                            <tr>
                                <th>Criteria</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($eval_data['part2'] as $criterion => $rating): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($criterion); ?></td>
                                <td>
                                    <span class="rating-display"><?php echo htmlspecialchars($rating); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if(isset($eval_data['part2_average'])): ?>
                    <div class="score-box">
                        <h5>PART 2 Average Score</h5>
                        <div class="score-value"><?php echo number_format($eval_data['part2_average'], 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if(isset($eval_data['part3'])): ?>
                    <!-- PART 3: Personal Qualities -->
                    <div class="section-title">
                        <i class="fas fa-user me-2"></i>PART 3: Personal Qualities
                    </div>
                    <table class="rating-table">
                        <thead>
                            <tr>
                                <th>Criteria</th>
                                <th>Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($eval_data['part3'] as $criterion => $rating): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($criterion); ?></td>
                                <td>
                                    <span class="rating-display"><?php echo htmlspecialchars($rating); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if(isset($eval_data['part3_average'])): ?>
                    <div class="score-box">
                        <h5>PART 3 Average Score</h5>
                        <div class="score-value"><?php echo number_format($eval_data['part3_average'], 2); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Overall Average -->
                    <?php if(isset($eval_data['overall_average'])): ?>
                    <div style="background: linear-gradient(135deg, var(--success), #5dbe5d); color: white; padding: 30px; border-radius: 12px; text-align: center; margin-top: 30px;">
                        <h5 style="margin: 0; opacity: 0.9;">OVERALL EVALUATION SCORE</h5>
                        <div style="font-size: 3rem; font-weight: 700; margin: 15px 0 0 0;">
                            <?php echo number_format($eval_data['overall_average'], 2); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Comments -->
                    <?php if(isset($eval_data['comments']) && !empty($eval_data['comments'])): ?>
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-top: 30px; border-left: 4px solid var(--secondary);">
                        <h6 style="color: var(--primary); font-weight: 600; margin-bottom: 10px;">
                            <i class="fas fa-comment me-2"></i>Evaluator's Comments
                        </h6>
                        <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($eval_data['comments'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php
                        $rSig = $evaluation['rater_signature'] ?? '';
                        $fSig = $evaluation['faculty_signature'] ?? '';
                        $rDate = $evaluation['rater_date'] ?? '';
                        $fDate = $evaluation['faculty_date'] ?? '';
                    ?>
                    <?php if (!empty($rSig) || !empty($fSig) || !empty($rDate) || !empty($fDate)): ?>
                    <div style="background: #ffffff; padding: 20px; border-radius: 12px; margin-top: 30px; border: 1px solid #e6e6e6;">
                        <h6 style="color: var(--primary); font-weight: 700; margin-bottom: 14px;">
                            <i class="fas fa-pen-nib me-2"></i>Signatures
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="border rounded p-3" style="background:#fff;">
                                    <div style="font-weight:600; margin-bottom: 6px;">Rater/Observer</div>
                                    <?php if (is_string($rSig) && strpos($rSig, 'data:image/') === 0): ?>
                                        <img src="<?php echo htmlspecialchars($rSig); ?>" alt="Rater signature" style="max-width:100%; height: 90px; object-fit: contain; display:block;" />
                                    <?php else: ?>
                                        <div style="min-height: 90px; display:flex; align-items:center; color:#6c757d;">
                                            <?php echo htmlspecialchars($rSig); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($rDate)): ?>
                                        <div style="margin-top: 8px; font-size: 0.9rem; color:#555;">Date: <?php echo htmlspecialchars($rDate); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded p-3" style="background:#fff;">
                                    <div style="font-weight:600; margin-bottom: 6px;">Faculty</div>
                                    <?php if (is_string($fSig) && strpos($fSig, 'data:image/') === 0): ?>
                                        <img src="<?php echo htmlspecialchars($fSig); ?>" alt="Faculty signature" style="max-width:100%; height: 90px; object-fit: contain; display:block;" />
                                    <?php else: ?>
                                        <div style="min-height: 90px; display:flex; align-items:center; color:#6c757d;">
                                            <?php echo htmlspecialchars($fSig); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($fDate)): ?>
                                        <div style="margin-top: 8px; font-size: 0.9rem; color:#555;">Date: <?php echo htmlspecialchars($fDate); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
