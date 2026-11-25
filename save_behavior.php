<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__.'/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_csrf_require();
    // Old tabs must not create incomplete logs or implicitly complete a task.
    header('Cache-Control: no-store');
    header('Location: charts.php', true, 303);
    exit();
}
header('Location: charts.php', true, 302);
exit();
