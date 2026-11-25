<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
$user_id = intval($_SESSION['user_id']);
$alreadyLogged = false;

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    app_csrf_require();
    $task_id = !empty($_POST['task_id']) ? intval($_POST['task_id']) : null;
    if ($task_id === null || $task_id < 1) {
        http_response_code(400); exit('Choose a task before logging a delay.');
    }
    if ($task_id !== null && !app_task_belongs_to_user($conn, $task_id, $user_id)) {
            http_response_code(400); exit('Task not found or access denied.');
    }
    $activeTask = $conn->prepare("SELECT id FROM tasks WHERE id=? AND user_id=? AND status IN ('Pending','In Progress') LIMIT 1");
    $activeTask->bind_param('ii', $task_id, $user_id);
    $activeTask->execute();
    $isActiveTask = $activeTask->get_result()->num_rows === 1;
    $activeTask->close();
    if (!$isActiveTask) {
        http_response_code(400); exit('Choose an active task before logging a delay.');
    }
    $reason = isset($_POST['reason']) ? mysqli_real_escape_string($conn, trim($_POST['reason'])) : null;
    $duration_minutes = !empty($_POST['duration_minutes']) ? intval($_POST['duration_minutes']) : null;
    $mood = isset($_POST['mood']) ? mysqli_real_escape_string($conn, trim($_POST['mood'])) : null;
    $notes = isset($_POST['notes']) ? mysqli_real_escape_string($conn, trim($_POST['notes'])) : null;
    if (!app_optional_int_in_range($duration_minutes, 1, 1440)) { http_response_code(400); exit('Invalid delay values.'); }

    if ($task_id !== null) {
        $check = $conn->prepare("SELECT id FROM procrastiblock_logs WHERE user_id=? AND task_id=? AND log_type='delay' LIMIT 1");
        $check->bind_param('ii', $user_id, $task_id);
        $check->execute();
        $alreadyLogged = $check->get_result()->num_rows > 0;
        $check->close();
    }

    if($reason !== null && $reason !== '' && !$alreadyLogged){
        $sql = "INSERT INTO procrastiblock_logs (user_id, log_type, task_id, reason, duration_minutes, mood, procrastination_level, notes) 
                VALUES ($user_id, 'delay', " . ($task_id !== null ? $task_id : 'NULL') . ", '$reason', " . ($duration_minutes !== null ? $duration_minutes : 'NULL') . ", " . ($mood !== null ? "'$mood'" : 'NULL') . ", NULL, " . ($notes !== null ? "'$notes'" : 'NULL') . ")";

        if(mysqli_query($conn, $sql)){
            if($task_id !== null){
                mysqli_query($conn, "UPDATE tasks SET status='In Progress' WHERE id=$task_id AND user_id=$user_id AND status='Pending'");
            }
        }
    }
}

header('Location: log_delay.php' . ($alreadyLogged ? '?duplicate=1' : ''));
exit();
?>
