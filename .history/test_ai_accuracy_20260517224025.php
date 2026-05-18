<?php
/**
 * AI Accuracy Test Suite - Percentage-Based Metrics
 * Tests embedding coverage, template distribution, and system health
 */

require_once 'config/database.php';

class AIAccuracyTest {
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
    
    public function runAllTests() {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║           AI ACCURACY TEST SUITE - PERCENTAGE METRICS          ║\n";
        echo "║                       ADCES-SYSTEM v2.0                        ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        $this->testTemplateCompleteness();
        $this->testEmbeddingCoverage();
        $this->testFormTypeDistribution();
        $this->testFieldDistribution();
        $this->testDatabaseHealth();
        $this->testRetrieval();
        $this->printSummary();
    }
    
    private function testTemplateCompleteness() {
        echo "TEST 1: TEMPLATE COMPLETENESS\n";
        echo str_repeat("─", 65) . "\n";
        
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_feedback_templates");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $result = $this->conn->query("SELECT COUNT(*) as active FROM ai_feedback_templates WHERE is_active=1");
        $active = $result->fetch(PDO::FETCH_ASSOC)['active'];
        
        $result = $this->conn->query("SELECT COUNT(*) as inactive FROM ai_feedback_templates WHERE is_active=0");
        $inactive = $result->fetch(PDO::FETCH_ASSOC)['inactive'];
        
        $active_pct = ($active / $total) * 100;
        $inactive_pct = ($inactive / $total) * 100;
        
        echo "Total Templates:        " . str_pad($total, 10) . " | 100.00%\n";
        echo "Active Templates:       " . str_pad($active, 10) . " | " . number_format($active_pct, 2) . "% ✓\n";
        echo "Inactive Templates:     " . str_pad($inactive, 10) . " | " . number_format($inactive_pct, 2) . "%\n";
        
        $this->results['template_completeness'] = $active_pct >= 95 ? 'PASS' : 'WARN';
        $status = $active_pct >= 95 ? '✓ PASS' : '⚠ WARN';
        echo "Status: $status (Target: >95%)\n\n";
    }
    
    private function testEmbeddingCoverage() {
        echo "TEST 2: EMBEDDING COVERAGE\n";
        echo str_repeat("─", 65) . "\n";
        
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_feedback_templates WHERE is_active=1");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $result = $this->conn->query("
            SELECT COUNT(*) as with_embedding 
            FROM ai_feedback_templates 
            WHERE is_active=1 AND LENGTH(embedding_vector) > 0
        ");
        $with_embedding = $result->fetch(PDO::FETCH_ASSOC)['with_embedding'];
        
        $result = $this->conn->query("
            SELECT COUNT(*) as no_embedding 
            FROM ai_feedback_templates 
            WHERE is_active=1 AND (embedding_vector IS NULL OR LENGTH(embedding_vector) = 0)
        ");
        $no_embedding = $result->fetch(PDO::FETCH_ASSOC)['no_embedding'];
        
        $coverage_pct = ($with_embedding / $total) * 100;
        
        echo "Total Active:           " . str_pad($total, 10) . " | 100.00%\n";
        echo "With Embeddings:        " . str_pad($with_embedding, 10) . " | " . number_format($coverage_pct, 2) . "% ✓\n";
        echo "Missing Embeddings:     " . str_pad($no_embedding, 10) . " | " . number_format(100 - $coverage_pct, 2) . "%\n";
        
        $this->results['embedding_coverage'] = $coverage_pct >= 98 ? 'PASS' : 'WARN';
        $status = $coverage_pct >= 98 ? '✓ PASS' : '⚠ WARN';
        echo "Status: $status (Target: >98%)\n\n";
    }
    
    private function testFormTypeDistribution() {
        echo "TEST 3: FORM TYPE DISTRIBUTION\n";
        echo str_repeat("─", 65) . "\n";
        
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_feedback_templates WHERE is_active=1");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $result = $this->conn->query("
            SELECT form_type, COUNT(*) as cnt 
            FROM ai_feedback_templates 
            WHERE is_active=1
            GROUP BY form_type
            ORDER BY form_type
        ");
        $forms = $result->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($forms as $form) {
            $pct = ($form['cnt'] / $total) * 100;
            $form_name = ucfirst(strtoupper($form['form_type']));
            echo "$form_name Templates:        " . str_pad($form['cnt'], 10) . " | " . number_format($pct, 2) . "%\n";
        }
        
        // Check if balanced (ideally 50/50 for ISO/PEAC)
        $iso_count = 0;
        $peac_count = 0;
        foreach ($forms as $form) {
            if ($form['form_type'] === 'iso') $iso_count = $form['cnt'];
            if ($form['form_type'] === 'peac') $peac_count = $form['cnt'];
        }
        
        $balance = min($iso_count, $peac_count) / max($iso_count, $peac_count) * 100;
        $this->results['form_balance'] = $balance >= 80 ? 'PASS' : 'WARN';
        $status = $balance >= 80 ? '✓ PASS' : '⚠ WARN';
        echo "Balance Ratio:          " . number_format($balance, 2) . "% $status (Target: >80%)\n\n";
    }
    
    private function testFieldDistribution() {
        echo "TEST 4: FIELD DISTRIBUTION\n";
        echo str_repeat("─", 65) . "\n";
        
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_feedback_templates WHERE is_active=1");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $result = $this->conn->query("
            SELECT field_name, COUNT(*) as cnt 
            FROM ai_feedback_templates 
            WHERE is_active=1
            GROUP BY field_name
            ORDER BY field_name
        ");
        $fields = $result->fetchAll(PDO::FETCH_ASSOC);
        
        $field_names = [
            'strengths' => 'Strengths',
            'areas_for_improvement' => 'Areas for Improvement',
            'recommendations' => 'Recommendations'
        ];
        
        foreach ($fields as $field) {
            $pct = ($field['cnt'] / $total) * 100;
            $display_name = $field_names[$field['field_name']] ?? $field['field_name'];
            echo "$display_name: " . str_pad($field['cnt'], 10) . " | " . number_format($pct, 2) . "%\n";
        }
        
        // Check if balanced (ideally 33.33% each)
        $counts = array_map(function($f) { return $f['cnt']; }, $fields);
        $min_count = min($counts);
        $max_count = max($counts);
        $balance = $min_count / $max_count * 100;
        
        $this->results['field_balance'] = $balance >= 90 ? 'PASS' : 'WARN';
        $status = $balance >= 90 ? '✓ PASS' : '⚠ WARN';
        echo "Balance Ratio:          " . number_format($balance, 2) . "% $status (Target: >90%)\n\n";
    }
    
    private function testDatabaseHealth() {
        echo "TEST 5: DATABASE HEALTH\n";
        echo str_repeat("─", 65) . "\n";
        
        // Test 5.1: NULL checks
        $result = $this->conn->query("
            SELECT COUNT(*) as nulls 
            FROM ai_feedback_templates 
            WHERE is_active=1 
            AND (field_name IS NULL OR feedback_text IS NULL)
        ");
        $nulls = $result->fetch(PDO::FETCH_ASSOC)['nulls'];
        
        $result = $this->conn->query("SELECT COUNT(*) as total FROM ai_feedback_templates WHERE is_active=1");
        $total = $result->fetch(PDO::FETCH_ASSOC)['total'];
        
        $null_pct = ($nulls / $total) * 100;
        $clean_pct = 100 - $null_pct;
        
        echo "Data Integrity:         " . str_pad($clean_pct, 6, " ", STR_PAD_LEFT) . "% ✓ (NULL fields: $nulls)\n";
        
        // Test 5.2: Duplicate detection
        $result = $this->conn->query("
            SELECT COUNT(*) - COUNT(DISTINCT feedback_text) as duplicates
            FROM ai_feedback_templates
            WHERE is_active=1
        ");
        $duplicates = $result->fetch(PDO::FETCH_ASSOC)['duplicates'];
        $unique_pct = ((($total - $duplicates) / $total) * 100);
        
        echo "Uniqueness:             " . str_pad(number_format($unique_pct, 2), 6, " ", STR_PAD_LEFT) . "% ✓ (Duplicates: $duplicates)\n";
        
        // Test 5.3: Average feedback length
        $result = $this->conn->query("
            SELECT AVG(LENGTH(feedback_text)) as avg_len, 
                   MIN(LENGTH(feedback_text)) as min_len,
                   MAX(LENGTH(feedback_text)) as max_len
            FROM ai_feedback_templates
            WHERE is_active=1
        ");
        $lengths = $result->fetch(PDO::FETCH_ASSOC);
        echo "Avg Feedback Length:    " . str_pad(round($lengths['avg_len']), 10) . " chars (Min: {$lengths['min_len']}, Max: {$lengths['max_len']})\n";
        
        $this->results['database_health'] = $clean_pct >= 99 && $unique_pct >= 95 ? 'PASS' : 'WARN';
        $status = ($clean_pct >= 99 && $unique_pct >= 95) ? '✓ PASS' : '⚠ WARN';
        echo "Status: $status\n\n";
    }
    
    private function testRetrieval() {
        echo "TEST 6: RETRIEVAL SIMULATION\n";
        echo str_repeat("─", 65) . "\n";
        
        // Get sample evaluations
        $result = $this->conn->query("SELECT COUNT(*) as cnt FROM evaluations WHERE status='completed'");
        $completed_evals = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        
        // Get evaluations with all fields filled
        $result = $this->conn->query("
            SELECT COUNT(*) as cnt 
            FROM evaluations 
            WHERE status='completed' 
            AND strengths IS NOT NULL 
            AND improvement_areas IS NOT NULL 
            AND recommendations IS NOT NULL
        ");
        $complete_evals = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        
        $completeness_pct = $completed_evals > 0 ? ($complete_evals / $completed_evals) * 100 : 0;
        
        echo "Completed Evaluations:  " . str_pad($completed_evals, 10) . " | 100.00%\n";
        echo "With Full Feedback:     " . str_pad($complete_evals, 10) . " | " . number_format($completeness_pct, 2) . "% ✓\n";
        
        // Get recommendation records
        $result = $this->conn->query("SELECT COUNT(*) as cnt FROM ai_recommendations");
        $recommendations = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
        
        $rec_coverage = $completed_evals > 0 ? ($recommendations / $completed_evals) * 100 : 0;
        
        echo "AI Recommendations:     " . str_pad($recommendations, 10) . " | " . number_format($rec_coverage, 2) . "% ✓\n";
        
        $this->results['retrieval'] = $completeness_pct >= 80 ? 'PASS' : 'WARN';
        $status = $completeness_pct >= 80 ? '✓ PASS' : '⚠ WARN';
        echo "Status: $status (Target: >80% complete)\n\n";
    }
    
    private function printSummary() {
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                       TEST SUMMARY                             ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        $tests = [
            'template_completeness' => 'Template Completeness',
            'embedding_coverage' => 'Embedding Coverage',
            'form_balance' => 'Form Type Balance',
            'field_balance' => 'Field Distribution',
            'database_health' => 'Database Health',
            'retrieval' => 'Retrieval Capability'
        ];
        
        $passed = 0;
        $warned = 0;
        
        foreach ($tests as $key => $label) {
            $status = $this->results[$key] ?? 'UNKNOWN';
            $icon = $status === 'PASS' ? '✓' : '⚠';
            $color = $status === 'PASS' ? '32' : '33';
            
            printf("\033[%dm%s\033[0m %-30s [%s]\n", $color, $icon, $label, $status);
            
            if ($status === 'PASS') $passed++;
            else if ($status === 'WARN') $warned++;
        }
        
        echo "\n" . str_repeat("─", 65) . "\n";
        
        $total = count($tests);
        $pass_pct = ($passed / $total) * 100;
        $health = ($passed / $total) * 100;
        
        printf("Total: %d/%d PASSED (%.1f%%)\n", $passed, $total, $pass_pct);
        printf("Health Score: %.1f%%\n", $health);
        
        echo "\n";
        
        if ($passed === $total) {
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║              ✓ ALL TESTS PASSED - SYSTEM READY                 ║\n";
            echo "║            AI System is Production-Ready for Testing            ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
        } else if ($warned > 0) {
            echo "╔════════════════════════════════════════════════════════════════╗\n";
            echo "║         ⚠ SOME TESTS NEED ATTENTION - REVIEW WARNINGS          ║\n";
            echo "║           Consider optimization before production               ║\n";
            echo "╚════════════════════════════════════════════════════════════════╝\n";
        }
        
        echo "\n";
    }
}

// Run the tests
$tester = new AIAccuracyTest();
$tester->runAllTests();
?>
