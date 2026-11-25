<?php
session_start();
require_once __DIR__ . '/../config/admin_database.php';

function admin_is_logged_in() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
}

function admin_require_login() {
    if (!admin_is_logged_in()) {
        header('Location: admin_login.php');
        exit();
    }
    global $conn;
    $stmt = $conn->prepare('SELECT role, password FROM admins WHERE id = ? AND is_active = 1');
    $id = (int)$_SESSION['admin_id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account || password_verify('admin123', $account['password'])) {
        unset($_SESSION['admin_id'], $_SESSION['admin_role']);
        header('Location: admin_login.php');
        exit();
    }
    $_SESSION['admin_role'] = $account['role'];
}

function admin_require_role($allowed_roles) {
    admin_require_login();
    if (!in_array($_SESSION['admin_role'], $allowed_roles)) {
        http_response_code(403);
        die('Access denied. Insufficient permissions.');
    }
}

function admin_login($username, $password) {
    global $conn;
    $username = trim($username);
    $stmt = $conn->prepare('SELECT * FROM admins WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 2');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    
    if ($result && mysqli_num_rows($result) === 1) {
        $admin = mysqli_fetch_assoc($result);
        if (!password_verify('admin123', $admin['password']) && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_fullname'] = $admin['fullname'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_email'] = $admin['email'];
            
            mysqli_query($conn, "UPDATE admins SET last_login = NOW() WHERE id = {$admin['id']}");
            return true;
        }
    }
    return false;
}

function admin_logout() {
    session_destroy();
    header('Location: admin_login.php');
    exit();
}

function admin_get_current() {
    if (admin_is_logged_in()) {
        return [
            'id' => $_SESSION['admin_id'],
            'username' => $_SESSION['admin_username'],
            'fullname' => $_SESSION['admin_fullname'],
            'role' => $_SESSION['admin_role'],
            'email' => $_SESSION['admin_email']
        ];
    }
    return null;
}
?>
