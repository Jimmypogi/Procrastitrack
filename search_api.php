<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(['tasks'=>[], 'subjects'=>[], 'deadlines'=>[]]);
    exit();
}

include __DIR__ . '/config/database.php';
$uid = intval($_SESSION['user_id']);
$q = trim($_GET['q'] ?? '');
if(strlen($q) < 2){
    echo json_encode(['tasks'=>[], 'subjects'=>[], 'deadlines'=>[]]);
    exit();
}
$q_like = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';

$tasks = [];
$res = mysqli_query($conn, "SELECT title FROM tasks WHERE user_id=$uid AND title LIKE '".mysqli_real_escape_string($conn,$q_like)."' ORDER BY created_at DESC LIMIT 5");
if($res){ while($r=mysqli_fetch_assoc($res)) $tasks[] = $r['title']; }

$subjects = [];
$res = mysqli_query($conn, "SELECT DISTINCT subject FROM tasks WHERE user_id=$uid AND subject LIKE '".mysqli_real_escape_string($conn,$q_like)."' ORDER BY subject LIMIT 5");
if($res){ while($r=mysqli_fetch_assoc($res)) $subjects[] = $r['subject']; }

$deadlines = [];
$res = mysqli_query($conn, "SELECT title FROM tasks WHERE user_id=$uid AND deadline IS NOT NULL AND " . app_task_deadline_upcoming_sql() . " AND (title LIKE '".mysqli_real_escape_string($conn,$q_like)."' OR subject LIKE '".mysqli_real_escape_string($conn,$q_like)."') ORDER BY deadline ASC, deadline_time ASC LIMIT 5");
if($res){ while($r=mysqli_fetch_assoc($res)) $deadlines[] = $r['title']; }

echo json_encode(['tasks'=>$tasks, 'subjects'=>$subjects, 'deadlines'=>$deadlines]);
