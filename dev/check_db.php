<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$database = new Database();
$db = $database->getConnection();
$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];

echo "ADCES DB Diagnostic" . PHP_EOL;
echo str_repeat('=', 40) . PHP_EOL;
echo "PHP version: " . PHP_VERSION . PHP_EOL;
echo "SAPI: " . php_sapi_name() . PHP_EOL;
echo "PDO loaded: " . (class_exists('PDO') ? 'yes' : 'no') . PHP_EOL;
echo "PDO drivers: " . (!empty($drivers) ? implode(', ', $drivers) : '(none)') . PHP_EOL;

echo PHP_EOL;
if ($db instanceof PDO) {
    echo "Connection: OK" . PHP_EOL;
    try {
        $stmt = $db->query('SELECT DATABASE() AS db_name, @@hostname AS host_name, @@port AS port_no');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Connected DB: " . ($row['db_name'] ?? '(unknown)') . PHP_EOL;
        echo "MySQL host: " . ($row['host_name'] ?? '(unknown)') . PHP_EOL;
        echo "MySQL port: " . ($row['port_no'] ?? '(unknown)') . PHP_EOL;
    } catch (Throwable $e) {
        echo "Connected, but metadata query failed: " . $e->getMessage() . PHP_EOL;
    }
} else {
    echo "Connection: FAIL" . PHP_EOL;
    echo "Error: " . ($database->getLastError() ?: 'Unknown connection error') . PHP_EOL;
}
