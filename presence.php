<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if(!isset($_SESSION['user_id'])){http_response_code(401);exit('{"ok":false}');}
require_once __DIR__.'/csrf.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!app_csrf_valid()){http_response_code(403);exit('{"ok":false}');}
require __DIR__.'/config/database.php';
$id=(int)$_SESSION['user_id'];
$s=$conn->prepare('UPDATE users SET last_activity=NOW() WHERE id=? AND is_active=1');
$s->bind_param('i',$id);$s->execute();
echo '{"ok":true}';
