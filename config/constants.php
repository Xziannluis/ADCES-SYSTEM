<?php
// User roles
define('ROLE_EDP', 'edp');
define('ROLE_PRESIDENT', 'president');
define('ROLE_VICE_PRESIDENT', 'vice_president');
define('ROLE_DEAN', 'dean');
define('ROLE_PRINCIPAL', 'principal');
define('ROLE_CHAIRPERSON', 'chairperson');
define('ROLE_SUBJECT_COORDINATOR', 'subject_coordinator');
define('ROLE_GRADE_LEVEL_COORDINATOR', 'grade_level_coordinator');

// Departments
define('DEPT_CTE', 'CTE');
define('DEPT_CAS', 'CAS');
define('DEPT_CCJE', 'CCJE');
define('DEPT_CBM', 'CBM');
define('DEPT_CCIS', 'CCIS');
define('DEPT_CTHM', 'CTHM');
define('DEPT_ELEM', 'Elementary');
define('DEPT_JHS', 'Junior High');
define('DEPT_SHS', 'Senior High');

// App settings
// Set to true only during local debugging.
define('APP_DEBUG', false);

// reCAPTCHA Keys (use environment variables in production)
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '6LduKn0sAAAAAMSZXTFREc6GKRQ5IdrlbY87H_Op');
define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '6LduKn0sAAAAAP6A9-fdWYknyD9a2eZZu-dVpst6');
?>