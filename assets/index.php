<?php
session_start();

// Redirect to login if not authenticated, otherwise to appropriate dashboard
    if(isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    	$role = $_SESSION['role'];
    	
    	if($role === 'edp') {
    		header("Location: edp/dashboard.php");
    	} elseif(in_array($role, ['president', 'vice_president'])) {
    		header("Location: leaders/dashboard.php");
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
} else {
	header("Location: login.php");
	exit();
}