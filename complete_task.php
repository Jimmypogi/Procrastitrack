<?php
session_start();
include 'config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])){
    require_once __DIR__ . '/csrf.php';
    app_csrf_require();
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    $user_id = (int)$_SESSION['user_id'];

    $taskQuery = $id ? mysqli_query($conn, "SELECT * FROM tasks WHERE id='$id' AND user_id='$user_id'") : false;
    $taskRow = $taskQuery ? mysqli_fetch_assoc($taskQuery) : null;

    if($taskRow && $taskRow['status'] !== 'Completed'){
        $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'tasks'");
        $hasCompletedAt = false;
        if($tbl && mysqli_num_rows($tbl)>0){
            $col = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'completed_at'");
            if($col && mysqli_num_rows($col)>0) $hasCompletedAt = true;
        }
        if($hasCompletedAt){
            mysqli_query($conn, "UPDATE tasks SET completion_deadline=deadline, completion_deadline_known=1, status='Completed', completed_at=NOW() WHERE id='$id' AND user_id='$user_id' AND status!='Completed'");
        } else {
            mysqli_query($conn, "UPDATE tasks SET status='Completed' WHERE id='$id' AND user_id='$user_id' AND status!='Completed'");
        }

        $completedNow = mysqli_affected_rows($conn) > 0;
        if ($completedNow) {
            $_SESSION['task_completion_celebration'] = ['title' => (string)$taskRow['title']];
        }
        $notifTbl = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
        if($completedNow && $notifTbl && mysqli_num_rows($notifTbl) > 0){
            $notifTitle = mysqli_real_escape_string($conn, '✅ Task Completed');
            $notifMessage = mysqli_real_escape_string($conn, "Great job! You completed '{$taskRow['title']}'.");
            $notifLink = mysqli_real_escape_string($conn, "profile.php#completed-tasks");
            mysqli_query($conn, "INSERT INTO notifications (user_id, title, message, link, priority, created_at) VALUES ('$user_id', '$notifTitle', '$notifMessage', '$notifLink', 'normal', NOW())");
        }
    }
}

if (!empty($_SESSION['task_completion_celebration'])) {
    header('Location: tasks.php?completed=1#task-list');
} else {
    header('Location: tasks.php');
}
exit();
?>
