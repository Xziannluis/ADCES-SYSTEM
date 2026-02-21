<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Evaluation.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();

$evaluation = new Evaluation($db);
$teacher = new Teacher($db);

// Get parameters
$export_type = $_GET['type'] ?? 'pdf';
$evaluation_id = $_GET['evaluation_id'] ?? 0;
$report_type = $_GET['report_type'] ?? 'single';
$academic_year = $_GET['academic_year'] ?? '2023-2024';
$semester = $_GET['semester'] ?? '';
$teacher_id = $_GET['teacher_id'] ?? '';

if($export_type == 'form') {
    exportEvaluationForm($evaluation_id, $academic_year, $semester);
} elseif($export_type == 'pdf') {
    if($report_type == 'single') {
        exportSingleEvaluationPDF($evaluation_id);
    } else {
        exportReportPDF($academic_year, $semester, $teacher_id);
    }
} elseif($export_type == 'csv') {
    if($report_type == 'single') {
        exportSingleEvaluationCSV($evaluation_id);
    } else {
        exportReportCSV($academic_year, $semester, $teacher_id);
    }
}

function exportEvaluationForm($evaluation_id, $academic_year, $semester) {
    global $evaluation, $teacher;
    
    // Set headers for HTML download
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="evaluation_form_' . date('Y-m-d') . '.html"');
    
    // Get evaluation data if it exists
    $eval_data = [];
    if($evaluation_id > 0) {
        $eval_data = $evaluation->getEvaluationById($evaluation_id);
    }
    
    // Output the evaluation form HTML
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Classroom Evaluation Form - Saint Michael College of Caraga</title>
        <script src="https://cdn.tailwindcss.com/3.4.16"></script>
        <script>tailwind.config={theme:{extend:{colors:{primary:'#1e40af',secondary:'#0891b2'},borderRadius:{'none':'0px','sm':'4px',DEFAULT:'8px','md':'12px','lg':'16px','xl':'20px','2xl':'24px','3xl':'32px','full':'9999px','button':'8px'}}}}</script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Pacifico&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" rel="stylesheet">
        <style>
            :where([class^="ri-"])::before { content: "\f3c2"; }
            @media print {
                body { margin: 0; padding: 0; }
                .no-print { display: none; }
            }
            .form-table { border-collapse: collapse; }
            .form-table td, .form-table th { border: 1px solid #000; padding: 0.25rem 0.5rem; }
            .checkbox-cell { width: 2rem; text-align: center; }
            .rating-table td { border: 1px solid #000; padding: 0.25rem; vertical-align: top; }
            .rating-checkbox { width: 1rem; height: 1rem; border: 1px solid #000; display: inline-block; margin: 0 auto; }
        </style>
    </head>
    <body class="bg-white text-gray-900 text-sm leading-tight">
        <div class="max-w-4xl mx-auto p-6 bg-white">
            <!-- Header Section -->
            <div class="flex items-start mb-6 border-b-2 border-gray-800 pb-4">
                <div class="w-20 h-20 mr-6 flex-shrink-0">
                    <div class="w-full h-full bg-blue-600 rounded-full flex items-center justify-center text-white font-bold text-lg border-4 border-yellow-400">
                        <div class="text-center">
                            <div class="text-xs">SMC</div>
                            <div class="text-xs">⚓</div>
                        </div>
                    </div>
                </div>
                <div class="flex-1 text-center">
                    <h1 class="text-lg font-bold mb-1">Saint Michael College of Caraga</h1>
                    <p class="text-xs mb-1">Brgy. 4, Nasipit, Agusan del Norte, Philippines</p>
                    <p class="text-xs mb-1">District 8, Brgy. Triangulo, Nasipit, Agusan del Norte, Philippines</p>
                    <p class="text-xs mb-1">Tel. Nos. +63 085 343-3521 / +63 085 285-4314</p>
                    <p class="text-xs text-blue-600 underline">www.smcnasipit.edu.ph</p>
                </div>
            </div>

            <h2 class="text-center text-base font-bold mb-6">CLASSROOM EVALUATION FORM</h2>

            <!-- Part 1: Faculty Information -->
            <div class="mb-6">
                <h3 class="font-bold mb-2">PART 1: Faculty Information</h3>
                <table class="w-full form-table text-xs">
                    <tr>
                        <td class="w-32 font-medium">Name of Faculty:</td>
                        <td class="border-b border-gray-400"><?php echo htmlspecialchars($eval_data['teacher_name'] ?? ''); ?></td>
                        <td class="w-24 font-medium">Academic Year:</td>
                        <td class="w-32 border-b border-gray-400"><?php echo htmlspecialchars($eval_data['academic_year'] ?? $academic_year); ?></td>
                        <td class="w-20 font-medium">Semester:</td>
                        <td class="w-12 text-center">(<?php echo ($eval_data['semester'] ?? '') == '1st' ? '✓' : ' '; ?>) 1st</td>
                        <td class="w-12 text-center">(<?php echo ($eval_data['semester'] ?? '') == '2nd' ? '✓' : ' '; ?>) 2nd</td>
                    </tr>
                    <tr>
                        <td class="font-medium">Department:</td>
                        <td class="border-b border-gray-400"><?php echo htmlspecialchars($eval_data['department'] ?? ''); ?></td>
                        <td class="font-medium">Subject/Time of Observation:</td>
                        <td colspan="3" class="border-b border-gray-400"><?php echo htmlspecialchars($eval_data['subject_observed'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2"></td>
                        <td class="font-medium">Date of Observation:</td>
                        <td colspan="3" class="border-b border-gray-400"><?php echo isset($eval_data['observation_date']) ? date('F j, Y', strtotime($eval_data['observation_date'])) : ''; ?></td>
                    </tr>
                    <tr>
                        <td colspan="7" class="font-medium">Type of Classroom Observation: Please check the appropriate box: 
                            (<?php echo ($eval_data['observation_type'] ?? '') == 'Formal' ? '✓' : ' '; ?>) Formal 
                            (<?php echo ($eval_data['observation_type'] ?? '') == 'Informal' ? '✓' : ' '; ?>) Informal
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Part 2: Mandatory Requirements -->
            <div class="mb-6">
                <h3 class="font-bold mb-2">PART 2: Mandatory Requirements for Teachers</h3>
                <table class="w-full form-table text-xs">
                    <tr>
                        <td colspan="7">
                            <div class="mb-2">Write (/) if presented to the observer, (x) if not presented.</div>
                            <div>( ) seat plan ( ) course syllabi ( ) others, please specify_________________</div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Part 3: Domains of Teaching Performance -->
            <div class="mb-6">
                <h3 class="font-bold mb-2">PART 3: Domains of Teaching Performance</h3>
                
                <!-- Rating Scale -->
                <div class="mb-4 text-xs">
                    <div class="mb-1"><strong>5 – Excellent</strong> - the teacher manifested the performance indicator which greatly exceeds standards</div>
                    <div class="mb-1"><strong>4 - Very Satisfactory</strong> - the teacher manifested the performance indicator which more than meets standards</div>
                    <div class="mb-1"><strong>3 – Satisfactory</strong> - the teacher manifested the performance indicator which meets standards</div>
                    <div class="mb-1"><strong>2 – Below Satisfactory</strong> - the teacher manifested the performance indicator which falls below standards</div>
                    <div class="mb-1"><strong>1 – Needs Improvement</strong> - the teacher barely manifested the expected performance indicator</div>
                </div>

                <!-- Communications Competence -->
                <table class="w-full rating-table text-xs mb-4">
                    <tr class="bg-gray-200">
                        <td class="font-bold w-96">Communications Competence</td>
                        <td class="checkbox-cell font-bold">5</td>
                        <td class="checkbox-cell font-bold">4</td>
                        <td class="checkbox-cell font-bold">3</td>
                        <td class="checkbox-cell font-bold">2</td>
                        <td class="checkbox-cell font-bold">1</td>
                        <td class="w-32 font-bold">Comments</td>
                    </tr>
                    <tr>
                        <td>1. Uses an audible voice that can be heard at the back of the room.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>2. Speaks fluently in the language of instruction.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>3. Facilitates a dynamic discussion.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>4. Uses engaging non-verbal cues (facial expression, gestures).</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>5. Uses words & expressions suited to the level of the students.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr class="bg-gray-100">
                        <td class="font-bold text-right">Average: ____</td>
                        <td colspan="6"></td>
                    </tr>
                </table>

                <!-- Management and Presentation of the Lesson -->
                <table class="w-full rating-table text-xs mb-4">
                    <tr class="bg-gray-200">
                        <td class="font-bold">Management and Presentation of the Lesson</td>
                        <td class="checkbox-cell font-bold">5</td>
                        <td class="checkbox-cell font-bold">4</td>
                        <td class="checkbox-cell font-bold">3</td>
                        <td class="checkbox-cell font-bold">2</td>
                        <td class="checkbox-cell font-bold">1</td>
                        <td class="font-bold">Comments</td>
                    </tr>
                    <tr>
                        <td>1. The TILO (Topic Intended Learning Outcomes) are clearly presented.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>2. Recall and connects previous lessons to the new lessons.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>3. The topic/lesson is introduced in an interesting & engaging way.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>4. Uses current issues, real life & local examples to enrich class discussion.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>5. Focuses class discussion on key concepts of the lesson.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>6. Encourages active participation among students and ask questions about the topic.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>7. Uses current instructional strategies and resources.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>8. Designs teaching aids that facilitate understanding of key concepts.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>9. Adapts teaching approach in the light of student feedback and reactions.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>10. Asks students using thought provoking questions (Art of Questioning).</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>11. Integrate the institutional core values to the lessons.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>12. Conduct the lesson using the principle of SMART.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr class="bg-gray-100">
                        <td class="font-bold text-right">Average: ____</td>
                        <td colspan="6"></td>
                    </tr>
                </table>

                <!-- Assessment of Students' Learning -->
                <table class="w-full rating-table text-xs mb-4">
                    <tr class="bg-gray-200">
                        <td class="font-bold">Assessment of Students' Learning</td>
                        <td class="checkbox-cell font-bold">5</td>
                        <td class="checkbox-cell font-bold">4</td>
                        <td class="checkbox-cell font-bold">3</td>
                        <td class="checkbox-cell font-bold">2</td>
                        <td class="checkbox-cell font-bold">1</td>
                        <td class="font-bold">Comments</td>
                    </tr>
                    <tr>
                        <td>1. Monitors students' understanding on key concepts discussed.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>2. Uses assessment tool that relates specific course competencies stated in the syllabus.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>3. Design test/quizzes/assignments and other assessment tasks that are competency-based.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>4. Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>5. Conducts formative assessment before evaluating and grading the learner's performance outcome.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>6. Monitors the formative assessment results and find ways to ensure learning for the learners.</td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td class="checkbox-cell"><div class="rating-checkbox"></div></td>
                        <td></td>
                    </tr>
                    <tr class="bg-gray-100">
                        <td class="font-bold text-right">Average: ____</td>
                        <td colspan="6"></td>
                    </tr>
                </table>

                <div class="border border-gray-800 p-2 mb-4">
                    <div class="font-bold text-center mb-2">Total Average: ________</div>
                </div>
            </div>

            <!-- Interpretation of Overall Rating -->
            <div class="mb-6">
                <h3 class="font-bold mb-2">Interpretation of Overall Rating</h3>
                <div class="text-xs space-y-1">
                    <div><strong>4.6-5.0 – Excellent</strong></div>
                    <div><strong>3.6-4.5 – Very Satisfactory</strong></div>
                    <div><strong>2.6-3.5 – Satisfactory</strong></div>
                    <div><strong>1.6-2.5 – Below Satisfactory</strong></div>
                    <div><strong>1.0-1.5 – Needs Improvement</strong></div>
                </div>
            </div>

            <!-- Feedback Sections -->
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <h3 class="font-bold mb-2 text-center bg-gray-200 p-1 border border-gray-800">STRENGTHS</h3>
                    <div class="border border-gray-800 h-32"><?php echo nl2br(htmlspecialchars($eval_data['strengths'] ?? '')); ?></div>
                </div>
                <div>
                    <h3 class="font-bold mb-2 text-center bg-gray-200 p-1 border border-gray-800">AREAS FOR IMPROVEMENT</h3>
                    <div class="border border-gray-800 h-32"><?php echo nl2br(htmlspecialchars($eval_data['improvement_areas'] ?? '')); ?></div>
                </div>
            </div>

            <div class="mb-6">
                <h3 class="font-bold mb-2 text-center bg-gray-200 p-1 border border-gray-800">RECOMMENDATIONS</h3>
                <div class="border border-gray-800 h-24"><?php echo nl2br(htmlspecialchars($eval_data['recommendations'] ?? '')); ?></div>
            </div>

            <div class="mb-6">
                <h3 class="font-bold mb-2">Agreement:</h3>
                <div class="border border-gray-800 h-16"></div>
            </div>

            <!-- Signature Section -->
            <div class="mb-6">
                <div class="mb-4">
                    <strong>Rater/Observer:</strong>
                </div>
                <div class="mb-4">
                    I certify that this classroom evaluation represents my best judgment.
                </div>
                <div class="grid grid-cols-2 gap-8 mb-6">
                    <div class="text-center">
                        <div class="border-b border-gray-800 mb-2 h-8"></div>
                        <div class="text-xs">Signature over printed name</div>
                    </div>
                    <div class="text-center">
                        <div class="border-b border-gray-800 mb-2 h-8"></div>
                        <div class="text-xs">Date</div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <strong>Faculty:</strong>
                </div>
                <div class="mb-4">
                    I certify that this evaluation result has been discussed with me during the post conference/debriefing.
                </div>
                <div class="grid grid-cols-2 gap-8 mb-6">
                    <div class="text-center">
                        <div class="border-b border-gray-800 mb-2 h-8"></div>
                        <div class="text-xs">Signature of Faculty over printed name</div>
                    </div>
                    <div class="text-center">
                        <div class="border-b border-gray-800 mb-2 h-8"></div>
                        <div class="text-xs">Date</div>
                    </div>
                </div>
            </div>

            <!-- Form Metadata -->
            <div class="bg-blue-800 text-white p-2 text-xs mb-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div><strong>Form Code No.</strong> : FM-DPM-SMCC-CMI-02</div>
                        <div><strong>Issue Status</strong> : 02</div>
                    </div>
                    <div>
                        <div><strong>Revision No.</strong> : 01</div>
                        <div><strong>Date Effective</strong> : 21 September 2023</div>
                        <div><strong>Approved By</strong> : President</div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between text-xs">
                <div>Member:</div>
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs">SMC</div>
                    <div class="w-8 h-8 bg-red-600 rounded-full flex items-center justify-center text-white text-xs">PH</div>
                    <div class="w-8 h-8 bg-green-600 rounded-full flex items-center justify-center text-white text-xs">QA</div>
                    <div class="w-8 h-8 bg-orange-600 rounded-full flex items-center justify-center text-white text-xs">ISO</div>
                    <div class="bg-blue-600 text-white px-2 py-1 rounded text-xs">
                        <i class="ri-mail-line mr-1"></i>
                        info@smcnasipit.edu.ph
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const checkboxes = document.querySelectorAll('.rating-checkbox');
                
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('click', function() {
                        const row = this.closest('tr');
                        const rowCheckboxes = row.querySelectorAll('.rating-checkbox');
                        
                        rowCheckboxes.forEach(cb => {
                            cb.style.backgroundColor = 'white';
                            cb.innerHTML = '';
                        });
                        
                        this.style.backgroundColor = '#1e40af';
                        this.innerHTML = '✓';
                        this.style.color = 'white';
                    });
                });
            });
        </script>
    </body>
    </html>
    <?php
}

function exportSingleEvaluationPDF($evaluation_id) {
    global $evaluation;
    
    // Get evaluation data
    $eval_data = $evaluation->getEvaluationById($evaluation_id);
    
    if(!$eval_data) {
        die("Evaluation not found");
    }
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="evaluation_' . $evaluation_id . '_' . date('Y-m-d') . '.pdf"');
    
    // Simple PDF content - in a real implementation, use FPDF or TCPDF
    $pdf_content = "
        Classroom Evaluation Report
        ===========================
        
        Teacher: {$eval_data['teacher_name']}
        Department: {$eval_data['department']}
        Academic Year: {$eval_data['academic_year']}
        Semester: {$eval_data['semester']}
        Subject: {$eval_data['subject_observed']}
        Observation Date: " . date('F j, Y', strtotime($eval_data['observation_date'])) . "
        
        Ratings:
        - Communications: {$eval_data['communications_avg']}
        - Management: {$eval_data['management_avg']}
        - Assessment: {$eval_data['assessment_avg']}
        - Overall: {$eval_data['overall_avg']}
        
        Strengths:
        {$eval_data['strengths']}
        
        Areas for Improvement:
        {$eval_data['improvement_areas']}
        
        Recommendations:
        {$eval_data['recommendations']}
    ";
    
    // This is a very basic PDF - in production, use a proper PDF library
    echo "%PDF-1.4\n";
    echo "1 0 obj\n";
    echo "<< /Type /Catalog /Pages 2 0 R >>\n";
    echo "endobj\n";
    echo "2 0 obj\n";
    echo "<< /Type /Pages /Kids [3 0 R] /Count 1 >>\n";
    echo "endobj\n";
    echo "3 0 obj\n";
    echo "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\n";
    echo "endobj\n";
    echo "4 0 obj\n";
    echo "<< /Length " . strlen($pdf_content) . " >>\n";
    echo "stream\n";
    echo $pdf_content . "\n";
    echo "endstream\n";
    echo "endobj\n";
    echo "xref\n";
    echo "0 5\n";
    echo "0000000000 65535 f \n";
    echo "0000000009 00000 n \n";
    echo "0000000058 00000 n \n";
    echo "0000000115 00000 n \n";
    echo "0000000224 00000 n \n";
    echo "trailer\n";
    echo "<< /Size 5 /Root 1 0 R >>\n";
    echo "startxref\n";
    echo "380\n";
    echo "%%EOF";
}

function exportSingleEvaluationCSV($evaluation_id) {
    global $evaluation;
    
    // Get evaluation data
    $eval_data = $evaluation->getEvaluationById($evaluation_id);
    
    if(!$eval_data) {
        die("Evaluation not found");
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="evaluation_' . $evaluation_id . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Field', 'Value']);
    
    // Add data
    fputcsv($output, ['Teacher Name', $eval_data['teacher_name']]);
    fputcsv($output, ['Department', $eval_data['department']]);
    fputcsv($output, ['Academic Year', $eval_data['academic_year']]);
    fputcsv($output, ['Semester', $eval_data['semester']]);
    fputcsv($output, ['Subject Observed', $eval_data['subject_observed']]);
    fputcsv($output, ['Observation Date', $eval_data['observation_date']]);
    fputcsv($output, ['Communications Average', $eval_data['communications_avg']]);
    fputcsv($output, ['Management Average', $eval_data['management_avg']]);
    fputcsv($output, ['Assessment Average', $eval_data['assessment_avg']]);
    fputcsv($output, ['Overall Average', $eval_data['overall_avg']]);
    fputcsv($output, ['Strengths', $eval_data['strengths']]);
    fputcsv($output, ['Improvement Areas', $eval_data['improvement_areas']]);
    fputcsv($output, ['Recommendations', $eval_data['recommendations']]);
    
    fclose($output);
}

function exportReportPDF($academic_year, $semester, $teacher_id) {
    global $evaluation;
    
    // Get evaluations for report
    $evaluations = $evaluation->getEvaluationsForReport($_SESSION['user_id'], $academic_year, $semester, $teacher_id);
    $stats = $evaluation->getDepartmentStats($_SESSION['department'], $academic_year, $semester);
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="evaluation_report_' . date('Y-m-d') . '.pdf"');
    
    // Simple PDF content
    $pdf_content = "Evaluation Report\n";
    $pdf_content .= "Academic Year: $academic_year\n";
    $pdf_content .= "Semester: " . ($semester ?: 'All') . "\n";
    $pdf_content .= "Total Evaluations: {$stats['total_evaluations']}\n";
    $pdf_content .= "Average Rating: " . number_format($stats['avg_rating'], 1) . "\n\n";
    
    if($evaluations->rowCount() > 0) {
        $pdf_content .= "Evaluation Details:\n";
        while($eval = $evaluations->fetch(PDO::FETCH_ASSOC)) {
            $pdf_content .= "Teacher: {$eval['teacher_name']} | ";
            $pdf_content .= "Date: " . date('M j, Y', strtotime($eval['observation_date'])) . " | ";
            $pdf_content .= "Subject: {$eval['subject_observed']} | ";
            $pdf_content .= "Overall: " . number_format($eval['overall_avg'], 1) . "\n";
        }
    }
    
    // Basic PDF structure
    echo "%PDF-1.4\n";
    echo "1 0 obj\n";
    echo "<< /Type /Catalog /Pages 2 0 R >>\n";
    echo "endobj\n";
    echo "2 0 obj\n";
    echo "<< /Type /Pages /Kids [3 0 R] /Count 1 >>\n";
    echo "endobj\n";
    echo "3 0 obj\n";
    echo "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\n";
    echo "endobj\n";
    echo "4 0 obj\n";
    echo "<< /Length " . strlen($pdf_content) . " >>\n";
    echo "stream\n";
    echo $pdf_content . "\n";
    echo "endstream\n";
    echo "endobj\n";
    echo "xref\n";
    echo "0 5\n";
    echo "0000000000 65535 f \n";
    echo "0000000009 00000 n \n";
    echo "0000000058 00000 n \n";
    echo "0000000115 00000 n \n";
    echo "0000000224 00000 n \n";
    echo "trailer\n";
    echo "<< /Size 5 /Root 1 0 R >>\n";
    echo "startxref\n";
    echo "380\n";
    echo "%%EOF";
}

function exportReportCSV($academic_year, $semester, $teacher_id) {
    global $evaluation;
    
    // Get evaluations for report
    $evaluations = $evaluation->getEvaluationsForReport($_SESSION['user_id'], $academic_year, $semester, $teacher_id);
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="evaluation_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['Teacher', 'Date', 'Subject', 'Comm Avg', 'Mgmt Avg', 'Assess Avg', 'Overall Avg', 'Rating', 'AI Recs', 'Evaluator']);
    
    if($evaluations->rowCount() > 0) {
        while($eval = $evaluations->fetch(PDO::FETCH_ASSOC)) {
            $rating = 'Needs Improvement';
            if($eval['overall_avg'] >= 4.6) $rating = 'Excellent';
            elseif($eval['overall_avg'] >= 3.6) $rating = 'Very Satisfactory';
            elseif($eval['overall_avg'] >= 2.9) $rating = 'Satisfactory';
            elseif($eval['overall_avg'] >= 1.8) $rating = 'Below Satisfactory';
            
            fputcsv($output, [
                $eval['teacher_name'],
                date('Y-m-d', strtotime($eval['observation_date'])),
                $eval['subject_observed'],
                number_format($eval['communications_avg'], 1),
                number_format($eval['management_avg'], 1),
                number_format($eval['assessment_avg'], 1),
                number_format($eval['overall_avg'], 1),
                $rating,
                $eval['ai_count'],
                $eval['evaluator_name']
            ]);
        }
    }
    
    fclose($output);
}
?>