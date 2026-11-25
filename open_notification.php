<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/csrf.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Use the Open button in Notifications.'); }
app_csrf_require();
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$uid = (int)$_SESSION['user_id'];
if (!$id || $id < 1) { http_response_code(400); exit('Invalid notification.'); }
$stmt = $conn->prepare('SELECT link FROM notifications WHERE id=? AND user_id=?');
$stmt->bind_param('ii',$id,$uid); $stmt->execute();
$notice = $stmt->get_result()->fetch_assoc();
if (!$notice) { http_response_code(404); exit('Notification not found.'); }
// Only navigate to a local PHP page; never trust a stored external URL.
$link = $notice['link'] ?? '';
if (!preg_match('/^[a-zA-Z0-9_-]+\.php(?:[?#][^\r\n]*)?$/D', $link) || !is_file(__DIR__.'/'.preg_split('/[?#]/',$link)[0])) $link='notifications.php';
$stmt = $conn->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?');
$stmt->bind_param('ii',$id,$uid); $stmt->execute();
header('Location: '.$link, true, 303);
exit;
