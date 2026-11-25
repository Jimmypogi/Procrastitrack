<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: notifications.php'); exit(); }
app_csrf_require();
$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
$ok = false;
if ($stmt) { $stmt->bind_param('i', $userId); $ok = $stmt->execute(); $stmt->close(); }
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json'); echo json_encode(['success' => $ok]); exit();
}
header('Location: notifications.php'); exit();
?>