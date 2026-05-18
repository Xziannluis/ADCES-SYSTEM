<?php
/**
 * Human vs AI Accuracy Test
 * Compares human-written evaluations with AI-generated suggestions
 */

require_once 'config/database.php';

class HumanVsAIAccuracyTest {
    private $db;
    private $conn;
    private $results = [];
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        if (!$this->conn) {
            die("Database connection failed: " . $this->db->getLastError());
        }
    }
    
    public function runAnalysis() {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║         HUMAN vs AI ACCURACY COMPARISON TEST                   ║\n";
        echo "║              ADCES-SYSTEM Evaluation Quality                   ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        $this->analyzeHumanEvaluations();
        $this->analyzeAIRecommendations();
        $this->compareHumanVsAI();
        $this->analyzeTextSimilarity();
        $this->generateFinalReport();
    }
    
    private function analyzeHumanEvaluations() {
        echo "PART 1: HUMAN-WRITTEN EVALUATIONS ANALYSIS\n";
        echo str_repeat("═", 65) . "\n\n";
        
        // Get completed evaluations
        $result = $this->conn->query("
            SELECT 
                id,
                strengths,
                improvement_areas,
                recommendations
            FROM evaluations 
            WHERE status='completed' 
            AND strengths IS NOT NULL
            LIMIT 20
        ");
        $evaluations = $result->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Total Completed Evaluations:  " . count($evaluations) . "\n\n";
        
        if (count($evaluations) == 0) {
            echo "⚠ No completed evaluations found for analysis.\n\n";
            return;
        }
        
        // Analyze field lengths
        $strengths_lengths = [];
        $improvement_lengths = [];
        $recommendations_lengths = [];
        
        foreach ($evaluations as $eval) {
            if (!empty($eval['strengths'])) {
                $strengths_lengths[] = strlen($eval['strengths']);
            }
            if (!empty($eval['improvement_areas'])) {
                $improvement_lengths[] = strlen($eval['improvement_areas']);
            }
            if (!empty($eval['recommendations'])) {
                $recommendations_lengths[] = strlen($eval['recommendations']);
            }
        }
        
        echo "HUMAN-WRITTEN TEXT STATISTICS:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        if (!empty($strengths_lengths)) {
            $avg = array_sum($strengths_lengths) / count($strengths_lengths);
            echo "Strengths Field:\n";
            echo "  Average Length:      " . number_format($avg, 0) . " characters\n";
            echo "  Word Count:          ~" . number_format($avg / 5, 0) . " words\n";
            echo "  Data Points:         " . count($strengths_lengths) . "\n\n";
            $this->results['human_strengths_avg'] = $avg;
        }
        
        if (!empty($improvement_lengths)) {
            $avg = array_sum($improvement_lengths) / count($improvement_lengths);
            echo "Improvement Areas Field:\n";
            echo "  Average Length:      " . number_format($avg, 0) . " characters\n";
            echo "  Word Count:          ~" . number_format($avg / 5, 0) . " words\n";
            echo "  Data Points:         " . count($improvement_lengths) . "\n\n";
            $this->results['human_improvement_avg'] = $avg;
        }
        
        if (!empty($recommendations_lengths)) {
            $avg = array_sum($recommendations_lengths) / count($recommendations_lengths);
            echo "Recommendations Field:\n";
            echo "  Average Length:      " . number_format($avg, 0) . " characters\n";
            echo "  Word Count:          ~" . number_format($avg / 5, 0) . " words\n";
            echo "  Data Points:         " . count($recommendations_lengths) . "\n\n";
            $this->results['human_recommendations_avg'] = $avg;
        }
    }
    
    private function analyzeAIRecommendations() {
        echo "PART 2: AI-GENERATED RECOMMENDATIONS ANALYSIS\n";
        echo str_repeat("═", 65) . "\n\n";
        
        // Get all AI recommendations
        $result = $this->conn->query("
            SELECT 
                id,
                evaluation_id,
                recommendation_text,
                generated_at
            FROM ai_recommendations
            LIMIT 50
        ");
        $recommendations = $result->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Total AI Recommendations:     " . count($recommendations) . "\n\n";
        
        if (count($recommendations) == 0) {
            echo "⚠ No AI recommendations found for analysis.\n\n";
            return;
        }
        
        // Analyze text lengths
        $rec_lengths = [];
        $categories = [];
        
        foreach ($recommendations as $rec) {
            $text = $rec['recommendation_text'];
            $rec_lengths[] = strlen($text);
            
            // Categorize recommendations
            if (stripos($text, 'communication') !== false) {
                $categories['communication']++;
            } elseif (stripos($text, 'assessment') !== false || stripos($text, 'check') !== false) {
                $categories['assessment']++;
            } elseif (stripos($text, 'lesson') !== false || stripos($text, 'management') !== false) {
                $categories['lesson_management']++;
            } else {
                $categories['other']++;
            }
        }
        
        $avg_length = array_sum($rec_lengths) / count($rec_lengths);
        
        echo "AI-GENERATED TEXT STATISTICS:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        echo "Recommendation Length:\n";
        echo "  Average Length:      " . number_format($avg_length, 0) . " characters\n";
        echo "  Word Count:          ~" . number_format($avg_length / 5, 0) . " words\n";
        echo "  Min Length:          " . min($rec_lengths) . " characters\n";
        echo "  Max Length:          " . max($rec_lengths) . " characters\n\n";
        
        echo "AI Recommendation Categories:\n";
        foreach ($categories as $category => $count) {
            $pct = ($count / count($recommendations)) * 100;
            echo "  " . ucfirst(str_replace('_', ' ', $category)) . ": " . $count . " (" . number_format($pct, 1) . "%)\n";
        }
        
        $this->results['ai_recommendations_avg'] = $avg_length;
        echo "\n";
    }
    
    private function compareHumanVsAI() {
        echo "PART 3: HUMAN vs AI COMPARISON\n";
        echo str_repeat("═", 65) . "\n\n";
        
        // Get evaluations with recommendations
        $result = $this->conn->query("
            SELECT 
                e.id,
                e.recommendations as human_recommendations,
                COUNT(ar.id) as ai_count
            FROM evaluations e
            LEFT JOIN ai_recommendations ar ON e.id = ar.evaluation_id
            WHERE e.status='completed'
            GROUP BY e.id
        ");
        $data = $result->fetchAll(PDO::FETCH_ASSOC);
        
        echo "COVERAGE ANALYSIS:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        $human_only = 0;
        $ai_only = 0;
        $both = 0;
        $neither = 0;
        
        $human_lengths = [];
        $ai_count_total = 0;
        $ai_evals_with_recs = 0;
        
        foreach ($data as $row) {
            $has_human = !empty($row['human_recommendations']);
            $has_ai = $row['ai_count'] > 0;
            
            if ($has_human) {
                $human_lengths[] = strlen($row['human_recommendations']);
            }
            
            if ($has_ai) {
                $ai_count_total += $row['ai_count'];
                $ai_evals_with_recs++;
            }
            
            if ($has_human && $has_ai) {
                $both++;
            } elseif ($has_human && !$has_ai) {
                $human_only++;
            } elseif (!$has_human && $has_ai) {
                $ai_only++;
            } else {
                $neither++;
            }
        }
        
        $total = count($data);
        
        echo "Evaluations with Human Recommendations Only: " . str_pad($human_only, 10) . " | " . number_format(($human_only/$total)*100, 1) . "%\n";
        echo "Evaluations with AI Recommendations Only:    " . str_pad($ai_only, 10) . " | " . number_format(($ai_only/$total)*100, 1) . "%\n";
        echo "Evaluations with BOTH Human & AI:            " . str_pad($both, 10) . " | " . number_format(($both/$total)*100, 1) . "%\n";
        echo "Evaluations with NEITHER:                    " . str_pad($neither, 10) . " | " . number_format(($neither/$total)*100, 1) . "%\n\n";
        
        echo "CONTENT ANALYSIS:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        if (!empty($human_lengths)) {
            $avg_human = array_sum($human_lengths) / count($human_lengths);
            echo "Human Recommendations:\n";
            echo "  Average Length:      " . number_format($avg_human, 0) . " characters\n";
            echo "  Total Evaluations:   " . count($human_lengths) . "\n\n";
        }
        
        if ($ai_evals_with_recs > 0) {
            $avg_ai_per_eval = $ai_count_total / $ai_evals_with_recs;
            echo "AI Recommendations:\n";
            echo "  Total Records:       " . $ai_count_total . "\n";
            echo "  Evaluations Covered: " . $ai_evals_with_recs . "\n";
            echo "  Average per Eval:    " . number_format($avg_ai_per_eval, 1) . " recommendations\n\n";
        }
        
        echo "OVERLAP PERCENTAGE:\n";
        echo str_repeat("─", 65) . "\n";
        echo "Both Human & AI:         " . number_format(($both/$total)*100, 1) . "% (Highest quality coverage)\n";
        echo "AI Coverage Rate:        " . number_format(($ai_evals_with_recs/$total)*100, 1) . "%\n\n";
    }
    
    private function analyzeTextSimilarity() {
        echo "PART 4: TEXT QUALITY METRICS\n";
        echo str_repeat("═", 65) . "\n\n";
        
        // Get template data
        $result = $this->conn->query("
            SELECT 
                COUNT(*) as total,
                AVG(LENGTH(feedback_text)) as avg_len,
                COUNT(DISTINCT field_name) as field_count
            FROM ai_feedback_templates 
            WHERE is_active=1
        ");
        $template_stats = $result->fetch(PDO::FETCH_ASSOC);
        
        echo "Template Library Quality:\n";
        echo "  Total Templates:     " . $template_stats['total'] . "\n";
        echo "  Avg Template Length: " . number_format($template_stats['avg_len'], 0) . " characters\n";
        echo "  Field Coverage:      " . $template_stats['field_count'] . " fields\n\n";
        
        // Analyze evaluation writing quality
        $result = $this->conn->query("
            SELECT 
                AVG(LENGTH(strengths) + LENGTH(improvement_areas) + LENGTH(recommendations)) as avg_total,
                COUNT(*) as eval_count
            FROM evaluations 
            WHERE status='completed'
            AND strengths IS NOT NULL
            AND improvement_areas IS NOT NULL
            AND recommendations IS NOT NULL
        ");
        $eval_quality = $result->fetch(PDO::FETCH_ASSOC);
        
        if ($eval_quality['eval_count'] > 0) {
            echo "Evaluator Writing Quality:\n";
            echo "  Evaluations:         " . $eval_quality['eval_count'] . "\n";
            echo "  Total Chars/Eval:    " . number_format($eval_quality['avg_total'], 0) . "\n";
            echo "  Estimated Words:     ~" . number_format($eval_quality['avg_total'] / 5, 0) . " per evaluation\n\n";
        }
        
        // Calculate accuracy percentages
        $template_quality_score = ($template_stats['total'] / 2400) * 100;
        $coverage_quality_score = min($template_stats['field_count'] / 3 * 100, 100);
        
        echo "QUALITY SCORES:\n";
        echo str_repeat("─", 65) . "\n";
        echo "Template Completeness:   " . number_format($template_quality_score, 1) . "%\n";
        echo "Field Coverage:          " . number_format($coverage_quality_score, 1) . "%\n";
        
        $this->results['text_quality_score'] = ($template_quality_score + $coverage_quality_score) / 2;
        echo "\n";
    }
    
    private function generateFinalReport() {
        echo "═════════════════════════════════════════════════════════════════\n";
        echo "PART 5: HUMAN vs AI FINAL ACCURACY REPORT\n";
        echo "═════════════════════════════════════════════════════════════════\n\n";
        
        // Get key metrics from database
        $result = $this->conn->query("
            SELECT 
                COUNT(*) as total_evals,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN recommendations IS NOT NULL AND recommendations != '' THEN 1 ELSE 0 END) as with_human_recs
            FROM evaluations
        ");
        $eval_stats = $result->fetch(PDO::FETCH_ASSOC);
        
        $result = $this->conn->query("
            SELECT COUNT(*) as total_recs FROM ai_recommendations
        ");
        $ai_recs_count = $result->fetch(PDO::FETCH_ASSOC)['total_recs'];
        
        $result = $this->conn->query("
            SELECT COUNT(DISTINCT evaluation_id) as evals_with_recs FROM ai_recommendations
        ");
        $ai_evals = $result->fetch(PDO::FETCH_ASSOC)['evals_with_recs'];
        
        // Calculate percentages
        $completion_pct = $eval_stats['total_evals'] > 0 ? ($eval_stats['completed'] / $eval_stats['total_evals']) * 100 : 0;
        $human_content_pct = $eval_stats['completed'] > 0 ? ($eval_stats['with_human_recs'] / $eval_stats['completed']) * 100 : 0;
        $ai_coverage_pct = $eval_stats['completed'] > 0 ? ($ai_evals / $eval_stats['completed']) * 100 : 0;
        $ai_density = $ai_evals > 0 ? $ai_recs_count / $ai_evals : 0;
        
        echo "KEY ACCURACY METRICS:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        printf("Evaluation Completion Rate:    %.1f%%  (%d/%d)\n", 
            $completion_pct, $eval_stats['completed'], $eval_stats['total_evals']);
        printf("Human Content Coverage:        %.1f%%  (%d/%d)\n", 
            $human_content_pct, $eval_stats['with_human_recs'], $eval_stats['completed']);
        printf("AI Recommendation Coverage:    %.1f%%  (%d/%d)\n", 
            $ai_coverage_pct, $ai_evals, $eval_stats['completed']);
        printf("AI Recommendations Density:    %.1f   (avg per eval)\n", $ai_density);
        
        echo "\n";
        
        // Calculate overall accuracy
        $overall_accuracy = ($completion_pct + $human_content_pct + $ai_coverage_pct) / 3;
        
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        printf("║  OVERALL HUMAN vs AI ACCURACY: %.1f%%                           ║\n", $overall_accuracy);
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        // Verdict
        echo "INTERPRETATION:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        if ($overall_accuracy >= 80) {
            echo "✓✓✓ EXCELLENT ALIGNMENT\n";
            echo "Human and AI systems are working in perfect harmony.\n";
            echo "Recommendation: Ready for full production deployment.\n\n";
        } else if ($overall_accuracy >= 60) {
            echo "✓✓ GOOD ALIGNMENT\n";
            echo "Human and AI systems are well-integrated with minor gaps.\n";
            echo "Recommendation: Continue pilot and optimize coverage.\n\n";
        } else if ($overall_accuracy >= 40) {
            echo "⚠ FAIR ALIGNMENT\n";
            echo "Some gaps between human and AI systems.\n";
            echo "Recommendation: Improve AI template quality and coverage.\n\n";
        } else {
            echo "✗ POOR ALIGNMENT\n";
            echo "Significant gaps between human and AI systems.\n";
            echo "Recommendation: System requires optimization before rollout.\n\n";
        }
        
        // Detailed recommendations
        echo "DETAILED RECOMMENDATIONS:\n";
        echo str_repeat("─", 65) . "\n\n";
        
        if ($human_content_pct < 80) {
            echo "1. Increase human content entry rate\n";
            echo "   → Train evaluators on importance of detailed feedback\n";
            echo "   → Set minimum character requirements\n\n";
        }
        
        if ($ai_coverage_pct < 80) {
            echo "2. Improve AI recommendation coverage\n";
            echo "   → Add more templates to database\n";
            echo "   → Improve query matching algorithm\n";
            echo "   → Expand form type support\n\n";
        }
        
        if ($ai_density < 1.5) {
            echo "3. Increase recommendations per evaluation\n";
            echo "   → Adjust similarity threshold\n";
            echo "   → Return top-N suggestions instead of single match\n\n";
        }
        
        echo "4. Regular monitoring\n";
        echo "   → Track human vs AI metrics weekly\n";
        echo "   → Collect evaluator feedback on AI suggestions\n";
        echo "   → Measure acceptance and edit rates\n\n";
    }
}

$tester = new HumanVsAIAccuracyTest();
$tester->runAnalysis();
?>
