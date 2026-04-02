<?php
session_start();

// --- Auto-start AI service if not running ---
require_once __DIR__ . '/includes/ai_autostart.php';

// Redirect to login if not authenticated, otherwise to appropriate dashboard
    if(isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    	$role = $_SESSION['role'];
    	
    	if($role === 'edp') {
    		header("Location: edp/dashboard.php");
    	} elseif(in_array($role, ['president', 'vice_president'])) {
    		header("Location: evaluators/dashboard.php");
    	} elseif($role === 'teacher') {
    		header("Location: teachers/dashboard.php");
    	} elseif(in_array($role, ['dean', 'principal'])) {
    		header("Location: evaluators/dashboard.php");
    	} elseif($role === 'chairperson') {
    		header("Location: evaluators/chairperson.php");
    	} elseif($role === 'subject_coordinator') {
    		header("Location: evaluators/subject_coordinator.php");
    	} elseif($role === 'grade_level_coordinator') {
    		header("Location: evaluators/grade_level_coordinator.php");
    	} else {
    		header("Location: evaluators/dashboard.php");
    	}
	exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADCES - AI-Driven Classroom Evaluation System</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Roboto', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            position: relative;
            overflow-x: hidden;
        }

        /* Full-page blurred background behind everything */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('assets/img/smccnasipit_cover.jpg') no-repeat center center;
            background-size: cover;
            filter: blur(8px);
            transform: scale(1.05);
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.15);
            z-index: 1;
        }

        /* Left Panel — logo side */
        .left-panel {
            width: 45%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
            padding: 40px;
        }

        .left-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 120%;
            background: linear-gradient(to right, rgba(10, 20, 40, 0.75) 0%, rgba(10, 20, 40, 0.7) 60%, rgba(10, 20, 40, 0.4) 85%, transparent 100%);
            z-index: 0;
        }

        .left-panel .logo-img {
            width: 200px;
            height: auto;
            margin-bottom: 28px;
            filter: drop-shadow(0 8px 24px rgba(0,0,0,0.5));
            position: relative;
            z-index: 1;
        }

        .left-panel h1 {
            color: #fff;
            font-size: 2rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
            position: relative;
            z-index: 1;
        }

        .left-panel .system-name {
            color: rgba(255,255,255,0.85);
            font-size: 1.1rem;
            font-weight: 400;
            letter-spacing: 0.5px;
            text-align: center;
            margin-bottom: 6px;
            text-shadow: 0 1px 6px rgba(0,0,0,0.4);
            position: relative;
            z-index: 1;
        }

        .left-panel .address {
            color: rgba(255,255,255,0.6);
            font-size: 0.95rem;
            text-align: center;
            text-shadow: 0 1px 4px rgba(0,0,0,0.3);
            position: relative;
            z-index: 1;
        }

        /* Right Panel — role cards */
        .right-panel {
            width: 55%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            position: relative;
            z-index: 2;
        }

        .right-content {
            width: 100%;
            max-width: 460px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .right-panel h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 6px;
            text-align: center;
            text-shadow: 0 2px 8px rgba(0,0,0,0.5);
        }

        .right-panel .subtitle {
            color: rgba(255,255,255,0.85);
            font-size: 0.95rem;
            margin-bottom: 28px;
            text-align: center;
        }

        /* Role Cards */
        .roles-container {
            width: 100%;
        }

        .role-card {
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid #e0e4ea;
            border-radius: 12px;
            padding: 16px 22px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            color: inherit;
        }

        .role-card:hover {
            border-color: #2a5298;
            box-shadow: 0 4px 18px rgba(42, 82, 152, 0.2);
            transform: translateY(-2px);
            color: inherit;
        }

        .role-card .role-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            color: #fff;
            flex-shrink: 0;
            margin-right: 16px;
        }

        .role-card .role-name {
            font-size: 1rem;
            font-weight: 600;
            color: #1a2a44;
            flex: 1;
        }

        .role-card .role-arrow {
            color: #adb5bd;
            font-size: 1rem;
            transition: transform 0.2s;
        }

        .role-card:hover .role-arrow {
            transform: translateX(4px);
            color: #2a5298;
        }

        /* Icon backgrounds */
        .bg-edp       { background: #6c757d; }
        .bg-president { background: #8B0000; }
        .bg-dean      { background: #2a5298; }
        .bg-coord     { background: #1a8754; }
        .bg-teacher   { background: #e67e22; }

        /* Footer */
        .footer-text {
            margin-top: 28px;
            color: rgba(255,255,255,0.55);
            font-size: 0.8rem;
            text-align: center;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .left-panel { width: 100%; padding: 36px 20px; }
            .left-panel .logo-img { width: 120px; margin-bottom: 16px; }
            .left-panel h1 { font-size: 1.4rem; }
            .right-panel { width: 100%; padding: 32px 20px; }
            .right-panel h2 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <!-- Left Panel -->
    <div class="left-panel">
        <img src="assets/img/SMCC_LOGO.webp" alt="SMCC Logo" class="logo-img">
        <h1>Saint Michael College of Caraga</h1>
        <p class="system-name">AI-Driven Classroom Evaluation System</p>
        <p class="address">Brgy. 4, Atupan St., Nasipit, Agusan del Norte</p>
    </div>

    <!-- Right Panel -->
    <div class="right-panel">
        <div class="right-content">
            <h2>AI-Driven Classroom Evaluation System</h2>
            <p class="subtitle">Select your role to continue</p>

            <div class="roles-container">
                <a href="login.php?role=edp" class="role-card">
                    <div class="role-icon bg-edp"><i class="fas fa-server"></i></div>
                    <span class="role-name">EDP</span>
                    <span class="role-arrow"><i class="fas fa-arrow-right"></i></span>
                </a>

                <a href="login.php?role=president" class="role-card">
                    <div class="role-icon bg-president"><i class="fas fa-crown"></i></div>
                    <span class="role-name">President / Vice President</span>
                    <span class="role-arrow"><i class="fas fa-arrow-right"></i></span>
                </a>

                <a href="login.php?role=dean" class="role-card">
                    <div class="role-icon bg-dean"><i class="fas fa-user-tie"></i></div>
                    <span class="role-name">Dean / Principal</span>
                    <span class="role-arrow"><i class="fas fa-arrow-right"></i></span>
                </a>

                <a href="login.php?role=coordinator" class="role-card">
                    <div class="role-icon bg-coord"><i class="fas fa-users-cog"></i></div>
                    <span class="role-name">Coordinator / Chairperson</span>
                    <span class="role-arrow"><i class="fas fa-arrow-right"></i></span>
                </a>

                <a href="login.php?role=teacher" class="role-card">
                    <div class="role-icon bg-teacher"><i class="fas fa-chalkboard-teacher"></i></div>
                    <span class="role-name">Teacher</span>
                    <span class="role-arrow"><i class="fas fa-arrow-right"></i></span>
                </a>
            </div>

            <p class="footer-text">&copy; <?php echo date('Y'); ?> Saint Michael College of Caraga | All Rights Reserved</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>