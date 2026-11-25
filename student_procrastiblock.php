<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
// Keep old bookmarks working by forwarding to the productivity overview.
header('Cache-Control: no-store');
header('Location: charts.php', true, 302);
exit();
