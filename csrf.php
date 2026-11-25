<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function app_csrf_token()
{
    if (empty($_SESSION['app_csrf_token'])) $_SESSION['app_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['app_csrf_token'];
}

function app_csrf_field()
{
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(app_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function app_csrf_valid($token = null)
{
    $token = $token === null ? ($_POST['csrf_token'] ?? '') : $token;
    return isset($_SESSION['app_csrf_token']) && is_string($token) && hash_equals($_SESSION['app_csrf_token'], $token);
}

function app_csrf_require()
{
    if (!app_csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload the page and try again.'); }
}

function app_task_belongs_to_user($conn, $taskId, $userId)
{
    $stmt = $conn->prepare('SELECT id FROM tasks WHERE id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ii', $taskId, $userId);
    $stmt->execute();
    $belongs = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $belongs;
}

function app_optional_int_in_range($value, $min, $max)
{
    if ($value === null || $value === '') return true;
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    return $parsed !== false && $parsed >= $min && $parsed <= $max;
}
