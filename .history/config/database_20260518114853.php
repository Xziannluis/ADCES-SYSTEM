<?php
date_default_timezone_set('Asia/Manila');

class Database {
    private $host = "127.0.0.1";
    private $port = "3306";
    private $db_name = "ai_classroom_eval";
    private $username = "root";
    private $password = "";
    public $conn;
    public $lastError = null;

    public function getConnection() {
        $this->conn = null;
        $this->lastError = null;
        $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];

        if (!in_array('mysql', $drivers, true)) {
            $this->lastError = 'PDO MySQL driver is not installed or enabled.';
            error_log('Database connection error: ' . $this->lastError);
            return null;
        }

        $dsnCandidates = [
            "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4",
            "mysql:host=localhost;port={$this->port};dbname={$this->db_name};charset=utf8mb4",
            "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
            "mysql:host=localhost;dbname={$this->db_name};charset=utf8mb4",
        ];

        foreach ($dsnCandidates as $dsn) {
            try {
                $this->conn = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $this->conn->exec("SET NAMES utf8mb4");
                return $this->conn;
            } catch(PDOException $exception) {
                $this->lastError = $exception->getMessage();
                error_log("Database connection error using DSN {$dsn}: " . $exception->getMessage());
            }
        }

        return null;
    }

    public function getLastError() {
        return $this->lastError;
    }
}
?>