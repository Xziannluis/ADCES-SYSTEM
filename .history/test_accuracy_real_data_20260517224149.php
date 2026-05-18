<?php
/**
 * AI Accuracy Test - Based on Real Database Data
 * Calculates actual percentages from database records
 */

require_once 'config/database.php';

class DatabaseAccuracyAnalyzer {
    private $db;
    private $conn;
    private $metrics = [];
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        if (!$this->conn) {
            die("Database connection failed: " . $this->db->getLastError());
        }
    }
    
    public function analyzeAll() {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║          AI ACCURACY TEST - REAL DATABASE DATA ANALYSIS        ║\n";
        echo "║                      ADCES-SYSTEM v2.0                         ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        $this->analyzeEvaluationCompletion();
        $this->analyzeTemplateUsage();
        $this->analyzeRecommendationCoverage();
        $this->analyzeFieldCompletion();
        $this->analyzeEvaluatorEfficiency();
        $this->analyzeUserAdoption();
        
        $this->printFinalSummary();
    }
    
    private function analyzeEvaluationCompletion() {
        echo "TEST 1: EVALUATION COMPLETION STATUS\n";
        echo str_repeat("─", 65) . "\n";
        
        // Total evaluations
        $result = $this->conn->query("SELECT COUNT(*) as total FROM evaluations");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        // By status
        $result = $this->conn->query("
            SELECT status, COUNT(*) as count 
            FROM evaluations 
            GROUP BY status
        ");
        $by_status = $result->fetchAll(PDO::FETCH_ASSOC);
        
        $draft = 0;
        $rescheduled = 0;
        $completed = 0;
        
        foreach ($by_status as $row) {
            switch ($row['status']) {
                case 'draft': $draft = $row['count']; break;
                case 'rescheduled': $rescheduled = $row['count']; break;
                case 'completed': $completed = $row['count']; break;
            }
        }
        
        $completion_rate = $total > 0 ? ($completed / $total) * 100 : 0;
        $draft_rate = $total > 0 ? ($draft / $total) * 100 : 0;
        $reschedule_rate = $total > 0 ? ($rescheduled / $total) * 100 : 0;
        
        echo "Total Evaluations:      " . str_pad($total, 10) . " | 100.00%\n";
        echo "  ✓ Completed:          " . str_pad($completed, 10) . " | " . number_format($completion_rate, 2) . "%\n";
        echo "  ⊙ Draft:              " . str_pad($draft, 10) . " | " . number_format($draft_rate, 2) . "%\n";
        echo "  ↻ Rescheduled:        " . str_pad($rescheduled, 10) . " | " . number_format($reschedule_rate, 2) . "%\n";
        
        $this->metrics['completion_rate'] = $completion_rate;
        $status = $completion_rate >= 50 ? '✓ PASS' : '⚠ LOW';
        echo "Status: $status (Target: >50%)\n\n";
    }
    
    private function analyzeTemplateUsage() {
        echo "TEST 2: TEMPLATE DATABASE DISTRIBUTION\n";
        echo str_repeat("─", 65) . "\n";
        
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_feedback_templates WHERE is_active=1");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        // By field
        $result = $this->conn->query("
            SELECT field_name, COUNT(*) as count 
            FROM ai_feedback_templates 
            WHERE is_active=1
            GROUP BY field_name
        ");
        $by_field = $result->fetchAll(PDO::FETCH_ASSOC);
        
        $field_data = [];
        foreach ($by_field as $row) {
            $pct = ($row['count'] / $total) * 100;
            $field_data[$row['field_name']] = [
                'count' => $row['count'],
                'pct' => $pct
            ];
        }
        
        echo "Total Templates:        " . str_pad($total, 10) . " | 100.00%\n";
        
        if (isset($field_data['strengths'])) {
            echo "  Strengths:            " . str_pad($field_data['strengths']['count'], 10) . " | " . number_format($field_data['strengths']['pct'], 2) . "%\n";
        }
        if (isset($field_data['areas_for_improvement'])) {
            echo "  Improvements:         " . str_pad($field_data['areas_for_improvement']['count'], 10) . " | " . number_format($field_data['areas_for_improvement']['pct'], 2) . "%\n";
        }
        if (isset($field_data['recommendations'])) {
            echo "  Recommendations:      " . str_pad($field_data['recommendations']['count'], 10) . " | " . number_format($field_data['recommendations']['pct'], 2) . "%\n";
        }
        
        // Check balance
        $counts = array_map(function($f) { return $f['count']; }, $field_data);
        $balance = count($counts) > 0 ? (min($counts) / max($counts)) * 100 : 0;
        
        echo "Field Balance:          " . number_format($balance, 2) . "% (Target: >90%)\n";
        $this->metrics['template_balance'] = $balance;
        $status = $balance >= 90 ? '✓ PASS' : '⚠ LOW';
        echo "Status: $status\n\n";
    }
    
    private function analyzeRecommendationCoverage() {
        echo "TEST 3: AI RECOMMENDATION COVERAGE\n";
        echo str_repeat("─", 65) . "\n";
        
        // Total recommendations
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_recommendations");
        $total_recs = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Completed evaluations with recommendations
        $result = $this->conn->query("
            SELECT COUNT(DISTINCT evaluation_id) as count 
            FROM ai_recommendations
        ");
        $evals_with_recs = $result->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Total completed evaluations
        $result = $this->conn->query("
            SELECT COUNT(*) as count FROM evaluations WHERE status='completed'
        ");
        $total_completed = $result->fetch(PDO::FETCH_ASSOC)['count'];
        
        $coverage_rate = $total_completed > 0 ? ($evals_with_recs / $total_completed) * 100 : 0;
        $avg_recs_per_eval = $evals_with_recs > 0 ? $total_recs / $evals_with_recs : 0;
        
        echo "Total AI Recommendations: " . str_pad($total_recs, 10) . " records\n";
        echo "Evaluations with Recs:    " . str_pad($evals_with_recs, 10) . " out of $total_completed\n";
        echo "Coverage Rate:            " . str_pad(number_format($coverage_rate, 2) . "%", 10) . " (Target: >80%)\n";
        echo "Avg Recs per Eval:        " . str_pad(number_format($avg_recs_per_eval, 1), 10) . " recommendations\n";
        
        $this->metrics['recommendation_coverage'] = $coverage_rate;
        $status = $coverage_rate >= 80 ? '✓ PASS' : '⚠ LOW';
        echo "Status: $status\n\n";
    }
    
    private function analyzeFieldCompletion() {
        echo "TEST 4: EVALUATION FIELD COMPLETION\n";
        echo str_repeat("─", 65) . "\n";
        
        $result = $this->conn->query("
            SELECT COUNT(*) as total FROM evaluations WHERE status='completed'
        ");
        $completed = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($completed == 0) {
            echo "No completed evaluations yet.\n";
            echo "Status: ⚠ INSUFFICIENT DATA\n\n";
            return;
        }
        
        // Check strengths field
        $result = $this->conn->query("
            SELECT COUNT(*) as filled 
            FROM evaluations 
            WHERE status='completed' AND strengths IS NOT NULL AND strengths != ''
        ");
        $strengths_filled = $result->fetch(PDO::FETCH_ASSOC)['filled'];
        $strengths_pct = ($strengths_filled / $completed) * 100;
        
        // Check improvements field
        $result = $this->conn->query("
            SELECT COUNT(*) as filled 
            FROM evaluations 
            WHERE status='completed' AND improvement_areas IS NOT NULL AND improvement_areas != ''
        ");
        $improvements_filled = $result->fetch(PDO::FETCH_ASSOC)['filled'];
        $improvements_pct = ($improvements_filled / $completed) * 100;
        
        // Check recommendations field
        $result = $this->conn->query("
            SELECT COUNT(*) as filled 
            FROM evaluations 
            WHERE status='completed' AND recommendations IS NOT NULL AND recommendations != ''
        ");
        $recommendations_filled = $result->fetch(PDO::FETCH_ASSOC)['filled'];
        $recommendations_pct = ($recommendations_filled / $completed) * 100;
        
        echo "Completed Evaluations:    " . str_pad($completed, 10) . " | 100.00%\n";
        echo "  Strengths Filled:       " . str_pad($strengths_filled, 10) . " | " . number_format($strengths_pct, 2) . "%\n";
        echo "  Improvements Filled:    " . str_pad($improvements_filled, 10) . " | " . number_format($improvements_pct, 2) . "%\n";
        echo "  Recommendations Filled: " . str_pad($recommendations_filled, 10) . " | " . number_format($recommendations_pct, 2) . "%\n";
        
        $avg_completion = ($strengths_pct + $improvements_pct + $recommendations_pct) / 3;
        echo "Average Field Completion: " . number_format($avg_completion, 2) . "% (Target: >80%)\n";
        
        $this->metrics['field_completion'] = $avg_completion;
        $status = $avg_completion >= 80 ? '✓ PASS' : '⚠ LOW';
        echo "Status: $status\n\n";
    }
    
    private function analyzeEvaluatorEfficiency() {
        echo "TEST 5: EVALUATOR EFFICIENCY\n";
        echo str_repeat("─", 65) . "\n";
        
        // Count unique evaluators
        $result = $this->conn->query("SELECT COUNT(DISTINCT evaluator_id) as count FROM evaluations");
        $total_evaluators = $result->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Evaluators who completed at least 1
        $result = $this->conn->query("
            SELECT COUNT(DISTINCT evaluator_id) as count 
            FROM evaluations 
            WHERE status='completed'
        ");
        $active_evaluators = $result->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Average evaluations per evaluator
        $result = $this->conn->query("
            SELECT COUNT(*) as total FROM evaluations WHERE status='completed'
        ");
        $total_completed = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $avg_per_evaluator = $active_evaluators > 0 ? $total_completed / $active_evaluators : 0;
        $active_rate = $total_evaluators > 0 ? ($active_evaluators / $total_evaluators) * 100 : 0;
        
        echo "Total Evaluators:         " . str_pad($total_evaluators, 10) . " | 100.00%\n";
        echo "Active Evaluators:        " . str_pad($active_evaluators, 10) . " | " . number_format($active_rate, 2) . "%\n";
        echo "Avg Evals/Evaluator:      " . str_pad(number_format($avg_per_evaluator, 1), 10) . " evaluations\n";
        
        $this->metrics['evaluator_activation'] = $active_rate;
        $status = $active_rate >= 30 ? '✓ PASS' : '⚠ LOW';
        echo "Status: $status (Activation target: >30%)\n\n";
    }
    
    private function analyzeUserAdoption() {
        echo "TEST 6: USER ADOPTION & NOTIFICATIONS\n";
        echo str_repeat("─", 65) . "\n";
        
        // Total users
        $result = $this->conn->query("SELECT COUNT(*) as total FROM users");
        $total_users = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Active users (those who created/have evaluations)
        $result = $this->conn->query("SELECT COUNT(DISTINCT evaluator_id) as count FROM evaluations");
        $active_users = $result->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Total notifications sent
        $result = $this->conn->query("SELECT COUNT(*) as total FROM notifications");
        $total_notifications = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Emails sent
        $result = $this->conn->query("SELECT COUNT(*) as total FROM notification_mail_logs WHERE status='sent'");
        $emails_sent = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $adoption_rate = $total_users > 0 ? ($active_users / $total_users) * 100 : 0;
        
        echo "Total Users:              " . str_pad($total_users, 10) . " | 100.00%\n";
        echo "Active Users:             " . str_pad($active_users, 10) . " | " . number_format($adoption_rate, 2) . "%\n";
        echo "Notifications Sent:       " . str_pad($total_notifications, 10) . " | Total in system\n";
        echo "Emails Delivered:         " . str_pad($emails_sent, 10) . " | Success rate\n";
        
        $this->metrics['user_adoption'] = $adoption_rate;
        $status = $adoption_rate >= 20 ? '✓ PASS' : '⚠ LOW';
        echo "Status: $status (Adoption target: >20%)\n\n";
    }
    
    private function printFinalSummary() {
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                    ACCURACY SUMMARY REPORT                     ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        $categories = [
            'Evaluation Completion' => 'completion_rate',
            'Template Balance' => 'template_balance',
            'Recommendation Coverage' => 'recommendation_coverage',
            'Field Completion' => 'field_completion',
            'Evaluator Activation' => 'evaluator_activation',
            'User Adoption' => 'user_adoption'
        ];
        
        $scores = [];
        echo "Metric Scores:\n";
        echo str_repeat("─", 65) . "\n";
        
        foreach ($categories as $label => $key) {
            $value = $this->metrics[$key] ?? 0;
            $scores[] = $value;
            
            // Determine status
            if ($value >= 80) {
                $status = '✓ EXCELLENT';
                $color = 32;
            } else if ($value >= 60) {
                $status = '✓ GOOD';
                $color = 33;
            } else if ($value >= 40) {
                $status = '⚠ FAIR';
                $color = 33;
            } else {
                $status = '✗ POOR';
                $color = 31;
            }
            
            // Progress bar
            $bar_length = (int)($value / 5);
            $bar = str_repeat("█", $bar_length) . str_repeat("░", 20 - $bar_length);
            
            printf("\033[%dm%-30s | %s | %6.1f%% | %s\033[0m\n", 
                $color, $label, $bar, $value, $status);
        }
        
        echo str_repeat("─", 65) . "\n\n";
        
        $overall_score = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;
        
        // Overall assessment
        echo "OVERALL ACCURACY SCORE: " . number_format($overall_score, 1) . "%\n\n";
        
        $bar_length = (int)($overall_score / 5);
        $bar = str_repeat("█", $bar_length) . str_repeat("░", 20 - $bar_length);
        echo "[$bar] " . number_format($overall_score, 1) . "%\n\n";
        
        // Verdict
        if ($overall_score >= 80) {
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║  ✓✓✓ EXCELLENT PERFORMANCE - READY FOR PRODUCTION             ║\n";
            echo "║  All key metrics are performing above targets                 ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
        } else if ($overall_score >= 60) {
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║  ✓✓ GOOD PERFORMANCE - MONITORING RECOMMENDED                ║\n";
            echo "║  Most metrics on track, some areas need optimization           ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
        } else if ($overall_score >= 40) {
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║  ⚠ FAIR PERFORMANCE - IMPROVEMENTS NEEDED                    ║\n";
            echo "║  Several areas require attention before production             ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
        } else {
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║  ✗ POOR PERFORMANCE - DEVELOPMENT IN PROGRESS                ║\n";
            echo "║  System is still in early stages, continued optimization       ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
        }
        
        echo "\n";
    }
}

$analyzer = new DatabaseAccuracyAnalyzer();
$analyzer->analyzeAll();
?>
