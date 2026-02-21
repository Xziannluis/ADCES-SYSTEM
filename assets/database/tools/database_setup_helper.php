<?php
/**
 * Database Setup Helper Script
 * This script helps you:
 * 1. Generate bcrypt hashed passwords
 * 2. Understand the database structure
 * 3. Create test credentials
 */

echo "========================================\n";
echo "DATABASE SETUP HELPER\n";
echo "========================================\n\n";

// Generate sample passwords
$testUsers = [
    'edp_user' => 'edp123',
    'dean_ccs' => 'dean123',
    'principal' => 'principal123',
    'chairperson_ccs' => 'chair123',
    'coordinator_ccs' => 'coord123',
    'president' => 'president123',
    'vp_academics' => 'vp123',
    'teacher_john' => 'teacher123',
    'teacher_mary' => 'teacher123',
    'teacher_robert' => 'teacher123',
];

echo "STEP 1: PASSWORD HASHES\n";
echo "====================\n";
echo "Copy these hashes into the database_complete.sql file\n\n";

$sqlInsert = "-- Update users table with hashed passwords:\nUPDATE `users` SET `password` = CASE \n";
$counter = 1;

foreach ($testUsers as $username => $password) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    echo "Username: $username\n";
    echo "Password: $password\n";
    echo "Hash: $hash\n\n";
    
    $sqlInsert .= "  WHEN `username` = '$username' THEN '$hash'\n";
}

$sqlInsert .= "END;\n";

echo "\nSTEP 2: TEST CREDENTIALS\n";
echo "=======================\n";
echo "Use these credentials to test the system:\n\n";
echo "EDP Admin:\n";
echo "  Username: edp_user\n";
echo "  Password: edp123\n";
echo "  Role: EDP\n\n";

echo "Dean (Computer Science):\n";
echo "  Username: dean_ccs\n";
echo "  Password: dean123\n";
echo "  Role: Dean\n\n";

echo "Teacher (John Smith):\n";
echo "  Username: teacher_john\n";
echo "  Password: teacher123\n";
echo "  Role: Teacher\n\n";

echo "Chairperson (Computer Science):\n";
echo "  Username: chairperson_ccs\n";
echo "  Password: chair123\n";
echo "  Role: Chairperson\n\n";

echo "\nSTEP 3: DATABASE IMPORT INSTRUCTIONS\n";
echo "===================================\n";
echo "1. Open phpMyAdmin or your MySQL client\n";
echo "2. Create a new database named: ai_classroom_eval\n";
echo "3. Import the database_complete.sql file\n";
echo "4. Update the password hashes in the users table with the hashes above\n\n";

echo "STEP 4: VERIFY DATABASE\n";
echo "======================\n";
echo "Run these queries to verify the setup:\n\n";
echo "-- Check users\n";
echo "SELECT id, username, name, role, department, status FROM users;\n\n";
echo "-- Check teachers\n";
echo "SELECT t.id, t.name, t.department, u.username FROM teachers t JOIN users u ON t.user_id = u.id;\n\n";
echo "-- Check evaluations\n";
echo "SELECT e.id, t.name as teacher, u.name as evaluator, e.status FROM evaluations e\n";
echo "JOIN teachers t ON e.teacher_id = t.id\n";
echo "JOIN users u ON e.evaluator_id = u.id;\n\n";

echo "STEP 5: UPDATE PHP CODE IF NEEDED\n";
echo "================================\n";
echo "Current database config in config/database.php:\n";
echo "  Host: localhost\n";
echo "  Database: ai_classroom_eval\n";
echo "  Username: root\n";
echo "  Password: (empty)\n\n";
echo "If your database credentials are different, update config/database.php\n\n";

echo "========================================\n";
echo "Setup Complete!\n";
echo "========================================\n";
?>
