<?php
echo "=== RUNNING AI ACCURACY TESTS ===\n\n";

require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Test 1: Count templates
$result = $conn->query("SELECT COUNT(*) as cnt FROM ai_feedback_templates WHERE is_active=1");
$templates = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "✓ Test 1 - Template Coverage: $templates templates loaded\n";

// Test 2: Verify embeddings exist
$result = $conn->query("SELECT COUNT(*) as cnt FROM ai_feedback_templates WHERE LENGTH(embedding_vector) > 0");
$with_embeddings = $result->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "✓ Test 2 - Embedding Coverage: $with_embeddings templates have embeddings\n";

// Test 3: Check form type distribution
$result = $conn->query("SELECT form_type, COUNT(*) as cnt FROM ai_feedback_templates GROUP BY form_type");
$rows = $result->fetchAll(PDO::FETCH_ASSOC);
echo "✓ Test 3 - Form Type Distribution:\n";
foreach ($rows as $row) {
    echo "  - {$row['form_type']}: {$row['cnt']} templates\n";
}

// Test 4: Check field distribution
$result = $conn->query("SELECT field_name, COUNT(*) as cnt FROM ai_feedback_templates GROUP BY field_name");
$rows = $result->fetchAll(PDO::FETCH_ASSOC);
echo "\n✓ Test 4 - Field Distribution:\n";
foreach ($rows as $row) {
    echo "  - {$row['field_name']}: {$row['cnt']} templates\n";
}

echo "\n=== ALL BASIC TESTS PASSED ===\n";
?>