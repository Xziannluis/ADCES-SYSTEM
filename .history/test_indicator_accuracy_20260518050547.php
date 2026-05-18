<?php
/**
 * INDICATOR ACCURACY TEST
 * Measures how well AI templates match evaluation form indicators/criteria
 * 
 * Key Metrics:
 * 1. Indicator Coverage - % of form criteria with templates
 * 2. Template-to-Indicator Mapping - How many templates per indicator
 * 3. Field Distribution - Balance across strengths/improvements/recommendations
 * 4. Form Type Alignment - Template coverage by ISO vs PEAC forms
 * 5. Indicator Specialization - Templates targeting specific criteria
 */

require_once __DIR__ . '/config/database.php';

$db = (new Database())->getConnection();

echo "\n";
echo "============================================================\n";
echo "    INDICATOR ACCURACY ANALYSIS - FORM vs TEMPLATES\n";
echo "============================================================\n\n";

// ============================================================
// TEST 1: GET ALL EVALUATION INDICATORS/CRITERIA
// ============================================================
echo "TEST 1: Evaluation Form Indicators\n";
echo "-----------------------------------\n";

$criteria_query = "
    SELECT 
        category,
        criterion_index,
        criterion_text,
        COUNT(*) as total_criteria
    FROM evaluation_criteria
    GROUP BY category, criterion_index
    ORDER BY category, criterion_index
";

$criteria_result = $db->query($criteria_query);
$all_criteria = $criteria_result->fetchAll(PDO::FETCH_ASSOC);

$criteria_by_category = [];
foreach ($all_criteria as $crit) {
    if (!isset($criteria_by_category[$crit['category']])) {
        $criteria_by_category[$crit['category']] = [];
    }
    $criteria_by_category[$crit['category']][] = $crit['criterion_text'];
}

echo "✓ Total Criteria Categories: " . count($criteria_by_category) . "\n";
$total_criteria = array_reduce($criteria_by_category, function($carry, $items) {
    return $carry + count($items);
}, 0);
echo "✓ Total Individual Criteria: $total_criteria\n\n";

foreach ($criteria_by_category as $category => $criteria) {
    echo "  [$category] - " . count($criteria) . " criteria\n";
    foreach (array_slice($criteria, 0, 3) as $c) {
        echo "    • " . substr($c, 0, 60) . "...\n";
    }
    if (count($criteria) > 3) {
        echo "    • ... and " . (count($criteria) - 3) . " more\n";
    }
}

echo "\n";

// ============================================================
// TEST 2: INDICATOR COVERAGE IN TEMPLATES
// ============================================================
echo "TEST 2: Indicator Coverage in AI Templates\n";
echo "-------------------------------------------\n";

$template_coverage = "
    SELECT 
        evaluation_comment,
        COUNT(*) as template_count,
        field_name,
        COUNT(DISTINCT field_name) as field_diversity
    FROM ai_feedback_templates
    WHERE is_active = 1
    GROUP BY evaluation_comment
    ORDER BY template_count DESC
";

$coverage_result = $db->query($template_coverage);
$coverage_data = $coverage_result->fetchAll(PDO::FETCH_ASSOC);

echo "✓ Unique Indicators in Templates: " . count($coverage_data) . "\n";
echo "✓ Total Template Records: " . array_sum(array_column($coverage_data, 'template_count')) . "\n\n";

// Find indicators NOT covered
$covered_indicators = array_column($coverage_data, 'evaluation_comment');
$uncovered = [];

foreach ($criteria_by_category as $category => $criteria_items) {
    foreach ($criteria_items as $criterion) {
        if (!in_array($criterion, $covered_indicators)) {
            $uncovered[] = [
                'indicator' => $criterion,
                'category' => $category
            ];
        }
    }
}

echo "✓ Indicators with Templates: " . count($covered_indicators) . " / $total_criteria (" . 
    round(count($covered_indicators) / $total_criteria * 100, 1) . "%)\n";
echo "⚠ Indicators WITHOUT Templates: " . count($uncovered) . "\n\n";

if (count($uncovered) > 0) {
    echo "Uncovered Indicators:\n";
    foreach (array_slice($uncovered, 0, 5) as $u) {
        echo "  ✗ [{$u['category']}] " . substr($u['indicator'], 0, 50) . "...\n";
    }
    if (count($uncovered) > 5) {
        echo "  ... and " . (count($uncovered) - 5) . " more\n";
    }
}

echo "\n";

// ============================================================
// TEST 3: TEMPLATE DEPTH PER INDICATOR
// ============================================================
echo "TEST 3: Template Depth Per Indicator (Coverage Quality)\n";
echo "------------------------------------------------------\n";

usort($coverage_data, function($a, $b) {
    return $b['template_count'] - $a['template_count'];
});

$depth_metrics = [
    'excellent' => 0,    // 10+ templates
    'good' => 0,         // 5-9 templates
    'adequate' => 0,     // 3-4 templates
    'minimal' => 0,      // 2 templates
    'single' => 0        // 1 template
];

foreach ($coverage_data as $item) {
    if ($item['template_count'] >= 10) $depth_metrics['excellent']++;
    elseif ($item['template_count'] >= 5) $depth_metrics['good']++;
    elseif ($item['template_count'] >= 3) $depth_metrics['adequate']++;
    elseif ($item['template_count'] >= 2) $depth_metrics['minimal']++;
    else $depth_metrics['single']++;
}

echo "✓ Excellent Coverage (10+ templates):  " . $depth_metrics['excellent'] . " indicators\n";
echo "✓ Good Coverage (5-9 templates):       " . $depth_metrics['good'] . " indicators\n";
echo "✓ Adequate Coverage (3-4 templates):   " . $depth_metrics['adequate'] . " indicators\n";
echo "⚠ Minimal Coverage (2 templates):      " . $depth_metrics['minimal'] . " indicators\n";
echo "⚠ Single Template:                     " . $depth_metrics['single'] . " indicators\n\n";

echo "Top 10 Best-Covered Indicators:\n";
foreach (array_slice($coverage_data, 0, 10) as $item) {
    echo "  ✓ [" . str_pad($item['template_count'], 2, ' ', STR_PAD_LEFT) . " templates] " . 
        substr($item['evaluation_comment'], 0, 55) . "\n";
}

echo "\n";

// ============================================================
// TEST 4: FIELD DISTRIBUTION ACCURACY
// ============================================================
echo "TEST 4: Field Distribution Accuracy\n";
echo "-----------------------------------\n";

$field_dist = "
    SELECT 
        field_name,
        COUNT(*) as count
    FROM ai_feedback_templates
    WHERE is_active = 1
    GROUP BY field_name
    ORDER BY field_name
";

$field_result = $db->query($field_dist);
$fields = $field_result->fetchAll(PDO::FETCH_ASSOC);

$total_templates = array_sum(array_column($fields, 'count'));

echo "✓ Total Active Templates: $total_templates\n";
echo "✓ Field Distribution:\n\n";

foreach ($fields as $field) {
    $pct = round($field['count'] / $total_templates * 100, 1);
    $bar_length = round($pct / 5);
    $bar = str_repeat('█', $bar_length) . str_repeat('░', 20 - $bar_length);
    echo "  {$field['field_name']}: " . str_pad($field['count'], 4, ' ', STR_PAD_LEFT) . 
        " ($pct%) |$bar|\n";
}

echo "\n";

// ============================================================
// TEST 5: FORM TYPE ACCURACY (ISO vs PEAC)
// ============================================================
echo "TEST 5: Form Type Alignment\n";
echo "---------------------------\n";

$form_dist = "
    SELECT 
        COALESCE(form_type, 'unspecified') as form_type,
        COUNT(*) as count,
        COUNT(DISTINCT evaluation_comment) as unique_indicators
    FROM ai_feedback_templates
    WHERE is_active = 1
    GROUP BY form_type
";

$form_result = $db->query($form_dist);
$forms = $form_result->fetchAll(PDO::FETCH_ASSOC);

foreach ($forms as $form) {
    $pct = round($form['count'] / $total_templates * 100, 1);
    echo "✓ {$form['form_type']}: " . str_pad($form['count'], 4, ' ', STR_PAD_LEFT) . 
        " templates ($pct%) - {$form['unique_indicators']} indicators\n";
}

echo "\n";

// ============================================================
// TEST 6: INDICATOR SPECIALIZATION ACCURACY
// ============================================================
echo "TEST 6: Indicator Specialization (Variety Per Indicator)\n";
echo "-------------------------------------------------------\n";

$specialization = "
    SELECT 
        evaluation_comment,
        COUNT(DISTINCT field_name) as field_count,
        COUNT(DISTINCT COALESCE(form_type, 'none')) as form_count,
        GROUP_CONCAT(DISTINCT field_name) as field_list
    FROM ai_feedback_templates
    WHERE is_active = 1
    GROUP BY evaluation_comment
    ORDER BY field_count DESC, form_count DESC
";

$spec_result = $db->query($specialization);
$spec_data = $spec_result->fetchAll(PDO::FETCH_ASSOC);

$multi_field_count = 0;
$multi_form_count = 0;

foreach ($spec_data as $item) {
    if ($item['field_count'] > 1) $multi_field_count++;
    if ($item['form_count'] > 1) $multi_form_count++;
}

echo "✓ Indicators Covering Multiple Fields: $multi_field_count / " . count($spec_data) . "\n";
echo "✓ Indicators Covering Multiple Forms:  $multi_form_count / " . count($spec_data) . "\n\n";

echo "Top Versatile Indicators:\n";
foreach (array_slice($spec_data, 0, 8) as $item) {
    echo "  ✓ " . substr($item['evaluation_comment'], 0, 45) . "\n";
    echo "    Fields: {$item['field_list']} | Forms: {$item['form_count']}\n";
}

echo "\n";

// ============================================================
// TEST 7: ACCURACY SCORING
// ============================================================
echo "TEST 7: Overall Accuracy Score\n";
echo "------------------------------\n";

$scores = [];

// Coverage score (0-20 points)
$coverage_pct = count($covered_indicators) / $total_criteria * 100;
$scores['coverage'] = $coverage_pct / 100 * 20;

// Depth score (0-20 points)
$depth_score = (
    ($depth_metrics['excellent'] * 4) +
    ($depth_metrics['good'] * 3) +
    ($depth_metrics['adequate'] * 2) +
    ($depth_metrics['minimal'] * 1)
) / (count($coverage_data) + 1) * 20;
$scores['depth'] = $depth_score;

// Distribution balance (0-20 points)
$expected_pct = 100 / count($fields);
$dist_variance = 0;
foreach ($fields as $field) {
    $actual_pct = $field['count'] / $total_templates * 100;
    $dist_variance += abs($actual_pct - $expected_pct);
}
$avg_variance = $dist_variance / count($fields);
$scores['distribution'] = max(0, 20 - ($avg_variance / 10));

// Form type balance (0-20 points)
$form_variance = 0;
foreach ($forms as $form) {
    $form_pct = $form['count'] / $total_templates * 100;
    $form_variance += abs($form_pct - 50); // Expect roughly 50/50 for ISO and PEAC
}
$scores['form_balance'] = max(0, 20 - ($form_variance / 10));

// Specialization (0-20 points)
$spec_score = ($multi_field_count + $multi_form_count) / (count($spec_data) * 2) * 20;
$scores['specialization'] = $spec_score;

$total_score = array_sum($scores);

echo "Breakdown by Metric:\n";
echo "  Coverage (0-20):       " . round($scores['coverage'], 1) . " pts (" . round($coverage_pct, 1) . "% of form criteria)\n";
echo "  Depth (0-20):          " . round($scores['depth'], 1) . " pts (template variety per indicator)\n";
echo "  Distribution (0-20):   " . round($scores['distribution'], 1) . " pts (field balance)\n";
echo "  Form Balance (0-20):   " . round($scores['form_balance'], 1) . " pts (ISO vs PEAC)\n";
echo "  Specialization (0-20): " . round($scores['specialization'], 1) . " pts (multi-field/form coverage)\n\n";

echo "════════════════════════════════════════════════════════════\n";
echo "TOTAL INDICATOR ACCURACY SCORE: " . round($total_score, 1) . " / 100\n";
echo "════════════════════════════════════════════════════════════\n\n";

// ============================================================
// TEST 8: COVERAGE MATRIX
// ============================================================
echo "TEST 8: Indicator-to-Form Coverage Matrix\n";
echo "----------------------------------------\n";

$matrix_query = "
    SELECT 
        ec.category,
        ec.criterion_text,
        COUNT(CASE WHEN aft.form_type = 'iso' THEN 1 END) as iso_count,
        COUNT(CASE WHEN aft.form_type = 'peac' THEN 1 END) as peac_count,
        COUNT(CASE WHEN aft.form_type IS NULL THEN 1 END) as unspecified_count,
        COUNT(*) as total
    FROM evaluation_criteria ec
    LEFT JOIN ai_feedback_templates aft ON ec.criterion_text = aft.evaluation_comment
    GROUP BY ec.category, ec.criterion_text
    ORDER BY ec.category, total DESC
";

$matrix_result = $db->query($matrix_query);
$matrix_data = $matrix_result->fetchAll(PDO::FETCH_ASSOC);

$uncovered_by_category = [];
$covered_indicators_count = 0;

foreach ($matrix_data as $row) {
    if ($row['total'] > 0) {
        $covered_indicators_count++;
    }
}

echo "✓ Total Criteria Evaluated: " . count($matrix_data) . "\n";
echo "✓ Criteria WITH Templates:  $covered_indicators_count (" . round($covered_indicators_count / count($matrix_data) * 100, 1) . "%)\n";
echo "✓ Criteria WITHOUT Templates: " . (count($matrix_data) - $covered_indicators_count) . "\n\n";

foreach (array_unique(array_column($matrix_data, 'category')) as $category) {
    $cat_data = array_filter($matrix_data, function($x) use ($category) {
        return $x['category'] == $category;
    });
    
    $cat_covered = count(array_filter($cat_data, function($x) {
        return $x['total'] > 0;
    }));
    
    echo "Category [$category]: $cat_covered / " . count($cat_data) . " covered\n";
}

echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "════════════════════════════════════════════════════════════\n";
echo "ACCURACY SUMMARY\n";
echo "════════════════════════════════════════════════════════════\n\n";

echo "KEY FINDINGS:\n";
echo "• Overall Accuracy Score: " . round($total_score, 1) . "/100\n";
echo "• Form Indicator Coverage: " . round($coverage_pct, 1) . "%\n";
echo "• Templates Available: $total_templates\n";
echo "• Unique Indicators Covered: " . count($covered_indicators) . " / $total_criteria\n";
echo "• Average Templates Per Indicator: " . round($total_templates / max(1, count($covered_indicators)), 1) . "\n";
echo "• Best-Covered Category: " . (function() use ($matrix_data) {
    $by_cat = [];
    foreach ($matrix_data as $row) {
        if (!isset($by_cat[$row['category']])) $by_cat[$row['category']] = ['covered' => 0, 'total' => 0];
        $by_cat[$row['category']]['total']++;
        if ($row['total'] > 0) $by_cat[$row['category']]['covered']++;
    }
    arsort($by_cat);
    $first = reset($by_cat);
    reset($by_cat);
    return key($by_cat) . " (" . $first['covered'] . "/" . $first['total'] . ")";
})() . "\n\n";

echo "RECOMMENDATIONS:\n";
if ($coverage_pct < 80) {
    echo "⚠ Expand template coverage for uncovered indicators\n";
}
if ($depth_score < 12) {
    echo "⚠ Add more template variations for existing indicators\n";
}
if ($scores['distribution'] < 15) {
    echo "⚠ Rebalance templates across strengths/improvements/recommendations\n";
}
if ($scores['form_balance'] < 15) {
    echo "⚠ Better balance templates between ISO and PEAC forms\n";
}
if ($coverage_pct >= 80 && $depth_score >= 12) {
    echo "✓ Indicator coverage is solid and well-balanced\n";
}

echo "\n";
?>
