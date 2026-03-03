<!-- ...existing code... -->
    <!-- ...existing code... -->
<nav class="sidebar">
    <div class="sidebar-header">
        <div style="display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 10px;">
            <img src="../assets/img/sd.webp" alt="SMCC Logo" style="width: 45px; height: auto; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));">
            <h4 style="margin: 0; font-weight: 700;">ADCES</h4>
        </div>
        <p class="user-info"><?php echo $_SESSION['name']; ?></p>
    </div>
    
    <ul class="sidebar-nav">
        <?php
            // Resolve dashboard link depending on role to avoid duplicate dashboards for coordinators
            $role = $_SESSION['role'] ?? '';
            $dashboard_link = 'dashboard.php';
            if ($role === 'chairperson') {
                $dashboard_link = 'chairperson.php';
            } elseif ($role === 'subject_coordinator') {
                $dashboard_link = 'subject_coordinator.php';
            } elseif ($role === 'grade_level_coordinator') {
                $dashboard_link = 'grade_level_coordinator.php';
            }
        ?>
        <li><a href="<?php echo $dashboard_link; ?>" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <?php if($_SESSION['role'] == 'edp'): ?>
            <li><a href="../edp/users.php" class="nav-link"><i class="fas fa-user-plus"></i> Create User Accounts</a></li>
        <?php elseif($_SESSION['role'] == 'superadmin'): ?>
            <li><a href="users.php" class="nav-link"><i class="fas fa-users"></i> User Management</a></li>
            <li><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <?php elseif(in_array($_SESSION['role'], ['president', 'vice_president'])): ?>
            <li><a href="../leaders/evaluation.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Evaluation</a></li>
            <li><a href="../leaders/teachers.php" class="nav-link"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
        <?php else: ?>
            <li><a href="evaluation.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Evaluation</a></li>
            <li><a href="teachers.php" class="nav-link"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
            <?php if(in_array($_SESSION['role'], ['dean', 'principal'])): ?>
                <li><a href="assign_coordinators.php" class="nav-link"><i class="fas fa-users-cog"></i> Coordinators</a></li>
            <?php endif; ?>
            <li><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <?php endif; ?>
    </ul>
</nav>