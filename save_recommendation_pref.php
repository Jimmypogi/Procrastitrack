<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
$user_id = intval($_SESSION['user_id']);

// Save recommendation preferences
if($_SERVER['REQUEST_METHOD'] === 'POST'){ app_csrf_require();
    $pomodoro = isset($_POST['pomodoro_interval']) ? intval($_POST['pomodoro_interval']) : 25;
    $break = isset($_POST['break_duration']) ? intval($_POST['break_duration']) : 5;
    $target = isset($_POST['target_hours']) ? intval($_POST['target_hours']) : 2;

    mysqli_query($conn, "UPDATE users SET pomodoro_interval=$pomodoro, break_duration=$break, target_hours=$target WHERE id=$user_id");

    $_SESSION['flash_message'] = 'Preferences saved!';
    $_SESSION['flash_type'] = 'success';
    header("Location: recommendations.php");
    exit();
}

header("Location: recommendations.php");
exit();
?>