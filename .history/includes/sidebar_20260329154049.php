<!-- ...existing code... -->
    <!-- ...existing code... -->
<nav class="sidebar">
    <div class="sidebar-header">
        <div style="display: flex; align-items: center; justify-content: center;">
            <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC Logo" style="width: 70px; height: auto; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));">
        </div>
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
            } elseif ($role === 'teacher') {
                $dashboard_link = '../teachers/dashboard.php';
            }
        ?>
        <?php if($_SESSION['role'] !== 'teacher'): ?>
        <li><a href="<?php echo $dashboard_link; ?>" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <?php endif; ?>
        <?php if($_SESSION['role'] == 'edp'): ?>
            <li><a href="../edp/users.php" class="nav-link"><i class="fas fa-user-plus"></i> Create User Accounts</a></li>
        <?php elseif($_SESSION['role'] == 'superadmin'): ?>
            <li><a href="users.php" class="nav-link"><i class="fas fa-users"></i> User Management</a></li>
            <li><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <?php elseif(in_array($_SESSION['role'], ['president', 'vice_president'])): ?>
            <li><a href="../evaluators/evaluation.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Evaluation</a></li>
            <li><a href="../evaluators/teachers.php" class="nav-link"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
            <li><a href="../evaluators/observation_plan.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Observation Plan</a></li>
            <li><a href="../evaluators/reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <?php elseif($_SESSION['role'] === 'teacher'): ?>
            <!-- Teacher only sees My Evaluations (added below) -->
        <?php else: ?>
            <li><a href="evaluation.php" class="nav-link"><i class="fas fa-clipboard-check"></i> Evaluation</a></li>
            <li><a href="teachers.php" class="nav-link"><i class="fas fa-chalkboard-teacher"></i> Teachers</a></li>
            <?php if(in_array($_SESSION['role'], ['dean', 'principal'])): ?>
                <li><a href="assign_coordinators.php" class="nav-link"><i class="fas fa-users-cog"></i> Coordinators</a></li>
            <?php endif; ?>
                <li><a href="observation_plan.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Observation Plan</a></li>
            <?php if(in_array($_SESSION['role'], ['dean', 'principal'])): ?>
                <li><a href="deactivated_teachers.php" class="nav-link"><i class="fas fa-user-slash"></i> Deactivated Teachers</a></li>
            <?php endif; ?>
            <li><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <?php endif; ?>

        <?php if ($_SESSION['role'] === 'teacher'): ?>
            <li><a href="../teachers/dashboard.php" class="nav-link"><i class="fas fa-file-alt"></i> My Evaluations</a></li>
            <li><a href="../teachers/observation_plan.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Observation Plan</a></li>
        <?php elseif(in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'])): ?>
            <li><a href="my_evaluations.php" class="nav-link"><i class="fas fa-file-alt"></i> My Evaluations</a></li>
        <?php elseif(in_array($_SESSION['role'], ['president', 'vice_president'])): ?>
            <li><a href="../evaluators/my_evaluations.php" class="nav-link"><i class="fas fa-file-alt"></i> My Evaluations</a></li>
        <?php endif; ?>

        <?php if (in_array($_SESSION['role'] ?? '', ['edp', 'dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president', 'teacher'], true)): ?>
            <li class="mt-3"><a href="../auth/logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        <?php endif; ?>
    </ul>
</nav>