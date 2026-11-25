<?php
session_start();
header('Content-Type: application/json');
if(!isset($_SESSION['user_id'])){ echo json_encode(['count'=>0]); exit(); }
include 'config/database.php';
$user_id = intval($_SESSION['user_id']);
// if notifications table doesn't exist return 0
$c = 0;
$tbl = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
if($tbl && mysqli_num_rows($tbl) > 0){
	$res = mysqli_query($conn, "SELECT COUNT(*) as c FROM notifications WHERE user_id=$user_id AND read_at IS NULL");
	if($res){ $r = mysqli_fetch_assoc($res); $c = intval($r['c']); }
}
echo json_encode(['count'=>$c]);
