<?php
// Returns JSON with last 7 days completed task counts and target hours per day
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id'])){ echo json_encode(['error'=>'unauth']); exit(); }
include 'config/database.php';
$user_id = intval($_SESSION['user_id']);

// A deadline is not evidence of completion. Leave unknown dates unchanged.
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$startDate = $today->modify('-6 days')->format('Y-m-d');
$endDate = $today->modify('+1 day')->format('Y-m-d');

$completedCounts = [];
$q = mysqli_query($conn, "SELECT DATE(completed_at) AS completed_date, COUNT(*) AS c FROM tasks WHERE user_id=$user_id AND status='Completed' AND completed_at IS NOT NULL AND completed_at >= '$startDate' AND completed_at < '$endDate' GROUP BY completed_date");
while($row = mysqli_fetch_assoc($q)){
    $completedCounts[$row['completed_date']] = intval($row['c']);
}

$days = [];
$completed = [];
$targets = [];
for($i=6;$i>=0;$i--){
    $d = $today->modify('-'.$i.' days');
    $label = $d->format('Y-m-d');
    $display = $d->format('D');
    $days[] = $display;
    $completed[] = $completedCounts[$label] ?? 0;
    $targets[] = 2;
}

echo json_encode(['labels'=>$days,'completed'=>$completed,'targets'=>$targets]);
