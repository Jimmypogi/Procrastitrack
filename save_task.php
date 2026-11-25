<?php
// Legacy endpoint: task creation now happens through add_task.php, where the
// server asks Gemini to assign priority before inserting the task.
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
header('Location: add_task.php');
exit();
