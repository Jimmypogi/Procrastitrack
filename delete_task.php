<?php
session_start();
include 'config/database.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

$returnTo = 'tasks';
$completedPage = 1;
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])){
    require_once __DIR__ . '/csrf.php';
    app_csrf_require();
    $returnTo = ($_POST['return_to'] ?? '') === 'history' ? 'history' : 'tasks';
    $requestedPage = filter_var($_POST['completed_page'] ?? 1, FILTER_VALIDATE_INT);
    $completedPage = $requestedPage && $requestedPage > 0 ? $requestedPage : 1;
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    $user_id = (int)$_SESSION['user_id'];

    $check = $id ? mysqli_query($conn, "SELECT id, title FROM tasks WHERE id='$id' AND user_id='$user_id'") : false;
    if($check && mysqli_num_rows($check) > 0){
        mysqli_query($conn, "DELETE FROM tasks WHERE id='$id' AND user_id='$user_id'");
        $_SESSION['flash_message'] = "Task deleted successfully.";
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_message'] = "Task not found or access denied.";
        $_SESSION['flash_type'] = 'error';
    }
}

if ($returnTo === 'history') {
    header('Location: profile.php?tab=history&completed_page=' . $completedPage . '#completed-tasks');
} else {
    header('Location: tasks.php');
}
exit();
?>
