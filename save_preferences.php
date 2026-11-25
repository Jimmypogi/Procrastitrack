<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
function preference_error($status, $message) { http_response_code($status); echo json_encode(['error'=>$message]); exit; }
if (!isset($_SESSION['user_id'])) preference_error(401, 'Please sign in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') preference_error(405, 'Use POST.');
require_once __DIR__.'/csrf.php';
if (!app_csrf_valid()) preference_error(403, 'Reload the page and try again.');
$columns=['dark_mode'=>'dark_mode','email_notif'=>'email_notifications','reminder_notif'=>'reminder_notifications','language'=>'language'];
$key=$_POST['preference']??''; $value=$_POST['value']??null;
if (!is_string($key)||!isset($columns[$key])||!is_string($value)) preference_error(422,'Invalid preference.');
if (!in_array($value,$key==='language'?['en','es','fr']:['0','1'],true)) preference_error(422,'Invalid preference value.');
try {
 require __DIR__.'/config/database.php';
 $stmt=$conn->prepare('UPDATE users SET '.$columns[$key].'=? WHERE id=?');
 $stmt->bind_param('si',$value,$_SESSION['user_id']); $stmt->execute();
 echo json_encode(['saved'=>true,'value'=>$value]);
} catch(Throwable $e) { error_log('Preference save: '.$e->getMessage()); preference_error(500,'Could not save. Please try again.'); }
