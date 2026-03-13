<?php
class Teacher {
    private $conn;
    private $table_name = "teachers";
    private $hasTeacherDepartmentsTable = null;

    public $id;
    public $name;
    public $department;
    public $status;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function hasTeacherDepartmentsTable() {
        if ($this->hasTeacherDepartmentsTable !== null) {
            return $this->hasTeacherDepartmentsTable;
        }

        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE 'teacher_departments'");
            $this->hasTeacherDepartmentsTable = ($stmt && $stmt->fetch(PDO::FETCH_NUM)) ? true : false;
        } catch (PDOException $e) {
            $this->hasTeacherDepartmentsTable = false;
            error_log('teacher_departments availability check failed: ' . $e->getMessage());
        }

        return $this->hasTeacherDepartmentsTable;
    }

    // Get teacher by ID
    public function getById($id) {
        if ($this->hasTeacherDepartmentsTable()) {
            $query = "SELECT t.*, GROUP_CONCAT(td.department ORDER BY td.department SEPARATOR ',') AS secondary_departments
                      FROM " . $this->table_name . " t
                      LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                      WHERE t.id = :id
                      GROUP BY t.id";
        } else {
            $query = "SELECT t.*, NULL AS secondary_departments
                      FROM " . $this->table_name . " t
                      WHERE t.id = :id";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return false;
    }

    // Get all teachers
    public function getAllTeachers($status = null) {
        $query = "SELECT * FROM " . $this->table_name;
        
        if ($status) {
            $query .= " WHERE status = :status";
        }
        
        $query .= " ORDER BY name ASC";
        
        $stmt = $this->conn->prepare($query);
        
        if ($status) {
            $stmt->bindParam(':status', $status);
        }
        
        $stmt->execute();
        return $stmt;
    }

    // Get teachers by department
    public function getByDepartment($department) {
        if ($this->hasTeacherDepartmentsTable()) {
            $query = "SELECT DISTINCT t.*
                      FROM " . $this->table_name . " t
                      LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                      WHERE t.department = :department OR td.department = :department
                      ORDER BY t.name ASC";
        } else {
            $query = "SELECT DISTINCT t.*
                      FROM " . $this->table_name . " t
                      WHERE t.department = :department
                      ORDER BY t.name ASC";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':department', $department);
        $stmt->execute();
        return $stmt;
    }

    // Get active teachers by department
    public function getActiveByDepartment($department) {
                if ($this->hasTeacherDepartmentsTable()) {
                    $query = "SELECT DISTINCT t.* FROM " . $this->table_name . " t 
                                        LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                                        WHERE (t.department = :department OR td.department = :department)
                                            AND t.status = 'active' 
                                        ORDER BY t.name ASC";
                } else {
                    $query = "SELECT DISTINCT t.* FROM " . $this->table_name . " t 
                                        WHERE t.department = :department
                                            AND t.status = 'active' 
                                        ORDER BY t.name ASC";
                }
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':department', $department);
                $stmt->execute();
                return $stmt;
    }

    // Get active teachers by multiple departments/programs
    public function getActiveByDepartments(array $departments) {
        $departments = array_values(array_filter(array_map('trim', $departments), function ($d) {
            return $d !== '';
        }));

        if (empty($departments)) {
            $query = "SELECT t.* FROM " . $this->table_name . " t 
                      WHERE t.status = 'active' 
                      ORDER BY t.name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt;
        }

        $placeholders = implode(',', array_fill(0, count($departments), '?'));
        if ($this->hasTeacherDepartmentsTable()) {
            $query = "SELECT DISTINCT t.* FROM " . $this->table_name . " t 
                      LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                      WHERE (t.department IN ($placeholders) OR td.department IN ($placeholders))
                        AND t.status = 'active'
                      ORDER BY t.name ASC";
        } else {
            $query = "SELECT DISTINCT t.* FROM " . $this->table_name . " t 
                      WHERE t.department IN ($placeholders)
                        AND t.status = 'active'
                      ORDER BY t.name ASC";
        }
        $stmt = $this->conn->prepare($query);
        $position = 1;
        foreach ($departments as $dept) {
            $stmt->bindValue($position++, $dept);
        }
        if ($this->hasTeacherDepartmentsTable()) {
            foreach ($departments as $dept) {
                $stmt->bindValue($position++, $dept);
            }
        }
        $stmt->execute();
        return $stmt;
    }

    public function getSecondaryDepartments($teacherId) {
        if (!$this->hasTeacherDepartmentsTable()) {
            return [];
        }
        $query = "SELECT department FROM teacher_departments WHERE teacher_id = :teacher_id ORDER BY department ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':teacher_id', $teacherId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function syncSecondaryDepartments($teacherId, array $departments) {
        if (!$this->hasTeacherDepartmentsTable()) {
            return true;
        }
        $departments = array_values(array_unique(array_filter(array_map('trim', $departments), function ($department) {
            return $department !== '';
        })));

        $deleteStmt = $this->conn->prepare("DELETE FROM teacher_departments WHERE teacher_id = :teacher_id");
        $deleteStmt->bindParam(':teacher_id', $teacherId);
        $deleteStmt->execute();

        if (empty($departments)) {
            return true;
        }

        $insertStmt = $this->conn->prepare("INSERT INTO teacher_departments (teacher_id, department, created_at) VALUES (:teacher_id, :department, NOW())");
        foreach ($departments as $department) {
            $insertStmt->bindParam(':teacher_id', $teacherId);
            $insertStmt->bindParam(':department', $department);
            $insertStmt->execute();
        }

        return true;
    }

    // Create new teacher
    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " (name, department, status, created_at) 
                  VALUES (:name, :department, :status, NOW())";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':department', $data['department']);
        $stmt->bindParam(':status', $data['status']);
        
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Teacher creation error: " . $e->getMessage());
            return false;
        }
    }

    // Update teacher
    public function update($id, $data) {
        $query = "UPDATE " . $this->table_name . " SET name = :name, department = :department, updated_at = NOW()";
        
        // Add password to query if provided
        if (isset($data['password']) && !empty($data['password'])) {
            $query .= ", password = :password";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':department', $data['department']);
        $stmt->bindParam(':id', $id);
        
        // Bind password if provided
        if (isset($data['password']) && !empty($data['password'])) {
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt->bindParam(':password', $hashed_password);
        }
        
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Teacher update error: " . $e->getMessage());
            return false;
        }
    }

    // Update teacher status
    public function updateStatus($teacher_id, $status) {
        $query = "UPDATE " . $this->table_name . " SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $teacher_id);
        
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Teacher status update error: " . $e->getMessage());
            return false;
        }
    }

    // Toggle teacher status between active and inactive
    public function toggleStatus($teacher_id) {
        $teacher = $this->getById($teacher_id);
        if (!$teacher) {
            return false;
        }
        
        $new_status = $teacher['status'] === 'active' ? 'inactive' : 'active';
        return $this->updateStatus($teacher_id, $new_status);
    }

    // Update teacher photo
    public function updatePhoto($teacher_id, $photo_filename) {
        $query = "UPDATE " . $this->table_name . " SET photo = :photo, updated_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':photo', $photo_filename);
        $stmt->bindParam(':id', $teacher_id);
        
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Teacher photo update error: " . $e->getMessage());
            return false;
        }
    }

    // Get total teachers count
    public function getTotalTeachers() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'];
    }

    // Get teachers by status
    public function getTeachersByStatus($status) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE status = :status ORDER BY name ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->execute();
        return $stmt;
    }

    // Search teachers
    public function searchTeachers($search_term, $department = null) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE name LIKE :search_term";
        
        if ($department) {
            $query .= " AND department = :department";
        }
        
        $query .= " ORDER BY name ASC";
        
        $stmt = $this->conn->prepare($query);
        $search_term = "%" . $search_term . "%";
        $stmt->bindParam(':search_term', $search_term);
        
        if ($department) {
            $stmt->bindParam(':department', $department);
        }
        
        $stmt->execute();
        return $stmt;
    }
}
?>