<?php
session_start();
if (isset($_SESSION['admin_id'])) { header('Location: dashboard.php'); } else { header('Location: admin_login.php'); }
exit();
