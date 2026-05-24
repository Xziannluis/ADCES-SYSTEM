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
$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role, t.name as teacher_name
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
        .report-table {
            min-width: 1100px;
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
                                    <th>Name of Teacher Observed</th>
                                    <th>Subject/Class Schedule</th>
                                    <th>Strength</th>
                                    <th>Areas for Improvement</th>
                                    <th>Recommendation/s</th>
                                    <th>Agreement</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo date('F j, Y', strtotime($observation_date)); ?></td>
                                    <td><?php echo htmlspecialchars($teacher_name); ?></td>
                                    <td><?php echo htmlspecialchars($all_evaluations[0]['subject_observed']); ?></td>
                                    <td><button class="btn btn-sm btn-link" onclick="showDetailsModal('strengths')">View</button></td>
                                    <td><button class="btn btn-sm btn-link" onclick="showDetailsModal('areas')">View</button></td>
                                    <td><button class="btn btn-sm btn-link" onclick="showDetailsModal('recommendations')">View</button></td>
                                    <td><button class="btn btn-sm btn-link" onclick="showDetailsModal('agreements')">View</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Modal for Details -->
                    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="detailsModalLabel">Observation Details</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body" id="modalBody">
                                    <!-- Content will be loaded here -->
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- end report-style output -->
                </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    <?php
    // Prepare data for JavaScript
    $strengths = [];
    $areas_for_improvement = [];
    $recommendations = [];
    $agreements = [];
    
    foreach($all_evaluations_data as $eval_data) {
        $eval = $eval_data['eval'];
        $details = $eval_data['details'];
        
        foreach($details as $detail) {
            if (!empty($detail['comments'])) {
                $comment = htmlspecialchars($detail['comments']);
                if (stripos($comment, 'strength') !== false || stripos($comment, 'good') !== false || stripos($comment, 'excellent') !== false) {
                    $strengths[] = ['evaluator' => $eval['evaluator_name'], 'comment' => $comment];
                } elseif (stripos($comment, 'improve') !== false || stripos($comment, 'better') !== false || stripos($comment, 'suggestion') !== false) {
                    $areas_for_improvement[] = ['evaluator' => $eval['evaluator_name'], 'comment' => $comment];
                } elseif (stripos($comment, 'recommend') !== false) {
                    $recommendations[] = ['evaluator' => $eval['evaluator_name'], 'comment' => $comment];
                } elseif (stripos($comment, 'agree') !== false || stripos($comment, 'acknowledge') !== false) {
                    $agreements[] = ['evaluator' => $eval['evaluator_name'], 'comment' => $comment];
                } else {
                    $strengths[] = ['evaluator' => $eval['evaluator_name'], 'comment' => $comment];
                }
            }
        }
        
        if (!empty($eval['strengths'])) {
            $strengths[] = ['evaluator' => $eval['evaluator_name'], 'comment' => htmlspecialchars($eval['strengths'])];
        }
        if (!empty($eval['improvement_areas'])) {
            $areas_for_improvement[] = ['evaluator' => $eval['evaluator_name'], 'comment' => htmlspecialchars($eval['improvement_areas'])];
        }
        if (!empty($eval['recommendations'])) {
            $recommendations[] = ['evaluator' => $eval['evaluator_name'], 'comment' => htmlspecialchars($eval['recommendations'])];
        }
        if (!empty($eval['agreement'])) {
            $agreements[] = ['evaluator' => $eval['evaluator_name'], 'comment' => htmlspecialchars($eval['agreement'])];
        }
    }
    ?>

    const detailsData = {
        strengths: <?php echo json_encode($strengths); ?>,
        areas: <?php echo json_encode($areas_for_improvement); ?>,
        recommendations: <?php echo json_encode($recommendations); ?>,
        agreements: <?php echo json_encode($agreements); ?>
    };

    function showDetailsModal(type) {
        const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
        const title = document.getElementById('detailsModalLabel');
        const body = document.getElementById('modalBody');
        
        let data = detailsData[type] || [];
        let titleText = 'Details';
        
        switch(type) {
            case 'strengths':
                titleText = 'Strengths';
                break;
            case 'areas':
                titleText = 'Areas for Improvement';
                break;
            case 'recommendations':
                titleText = 'Recommendations';
                break;
            case 'agreements':
                titleText = 'Agreements';
                break;
        }
        
        title.textContent = titleText;
        
        if (data.length === 0) {
            body.innerHTML = '<p class="text-muted"><em>No comments provided.</em></p>';
        } else {
            let html = '<div>';
            data.forEach(item => {
                html += '<div class="mb-3"><strong>' + item.evaluator + ':</strong><p class="ms-3 mb-0">' + item.comment + '</p></div>';
            });
            html += '</div>';
            body.innerHTML = html;
        }
        
        modal.show();
    }
    </script>
