<?php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false, 'error'=>'unauthorized']);
    exit();
}

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);

// Get all notifications
$notifications = array();
$notifTbl = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
if($notifTbl && mysqli_num_rows($notifTbl) > 0){
    $query = "SELECT * FROM notifications WHERE user_id=$user_id ORDER BY (read_at IS NOT NULL), created_at DESC LIMIT 200";
    $result = mysqli_query($conn, $query);
    if($result){
        while($row = mysqli_fetch_assoc($result)){
            $notifications[] = array(
                'id' => intval($row['id']),
                'title' => htmlspecialchars($row['title']),
                'message' => htmlspecialchars($row['message']),
                'link' => $row['link'] ? htmlspecialchars($row['link']) : '',
                'priority' => $row['priority'],
                'created_at' => $row['created_at'],
                'read_at' => $row['read_at'],
                'is_unread' => is_null($row['read_at'])
            );
        }
    }
}

// Get unread count
$unread_count = 0;
if($notifTbl && mysqli_num_rows($notifTbl) > 0){
    $countQuery = "SELECT COUNT(*) as c FROM notifications WHERE user_id=$user_id AND read_at IS NULL";
    $countResult = mysqli_query($conn, $countQuery);
    if($countResult){
        $countRow = mysqli_fetch_assoc($countResult);
        $unread_count = intval($countRow['c']);
    }
}

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count
]);
exit();
?>
