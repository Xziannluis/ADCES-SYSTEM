<?php
require __DIR__ . '/config/database.php';
$db=(new Database())->getConnection();
$tid=7;
echo "-- evaluations\n";
$st=$db->prepare("SELECT id,teacher_id,academic_year,semester,observation_date,observation_time,observation_room,subject_area,subject_observed,status,updated_at FROM evaluations WHERE teacher_id=:tid ORDER BY id DESC");
$st->execute([':tid'=>$tid]);
foreach($st as $r){echo implode('|',[$r['id'],$r['teacher_id'],$r['academic_year'],$r['semester'],$r['observation_date'],$r['observation_time'],$r['observation_room'],$r['subject_area'],$r['subject_observed'],$r['status'],$r['updated_at']]).PHP_EOL;}
echo "-- teacher row\n";
$st=$db->prepare("SELECT id,name,evaluation_schedule,evaluation_schedule_end,evaluation_room,evaluation_subject_area,evaluation_subject,evaluation_semester,evaluation_form_type,scheduled_department,updated_at FROM teachers WHERE id=:tid");
$st->execute([':tid'=>$tid]);
$r=$st->fetch(PDO::FETCH_ASSOC); if($r){echo implode('|',$r).PHP_EOL;}
?>
