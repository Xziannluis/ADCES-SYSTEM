<?php
$tests = [
    'localhost' => 'mysql:host=localhost;dbname=ai_classroom_eval',
    '127.0.0.1' => 'mysql:host=127.0.0.1;port=3306;dbname=ai_classroom_eval',
];

foreach ($tests as $label => $dsn) {
    try {
        $db = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        echo $label . ': OK' . PHP_EOL;
    } catch (Throwable $e) {
        echo $label . ': FAIL - ' . $e->getMessage() . PHP_EOL;
    }
}
