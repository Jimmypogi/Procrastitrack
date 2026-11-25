<?php
/** Short-lived, task-scoped, read-only access for links delivered by email. */
function app_create_task_email_access_link(mysqli $conn, int $userId, int $taskId, string $appUrl, int $lifetimeSeconds = 604800): ?string {
    $baseUrl = rtrim(trim($appUrl), '/');
    $parts = parse_url($baseUrl);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) return null;

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        error_log('Could not create a task email access token.');
        return null;
    }
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + max(300, min($lifetimeSeconds, 604800)));
    try {
        $stmt = $conn->prepare('INSERT INTO task_email_access_tokens (user_id, task_id, token_hash, expires_at) VALUES (?, ?, ?, ?)');
        if (!$stmt) return null;
        $stmt->bind_param('iiss', $userId, $taskId, $tokenHash, $expiresAt);
        $saved = $stmt->execute();
        $stmt->close();
        if (!$saved) return null;
    } catch (Throwable $error) {
        error_log('Could not save a task email access token.');
        return null;
    }

    // Opportunistically prune expired tokens without making email delivery fail.
    try {
        $conn->query('DELETE FROM task_email_access_tokens WHERE expires_at < NOW() LIMIT 500');
    } catch (Throwable $error) {
        error_log('Expired task email links could not be pruned.');
    }
    return $baseUrl . '/view_task.php?email_task=1&id=' . $taskId . '&token=' . $token;
}

function app_verify_task_email_access(mysqli $conn, array $query): ?array {
    $taskId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $token = $query['token'] ?? '';
    if (!$taskId || !is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;

    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT a.user_id, a.task_id, a.expires_at FROM task_email_access_tokens a INNER JOIN users u ON u.id=a.user_id AND u.is_active=1 WHERE a.token_hash=? AND a.task_id=? AND a.expires_at >= NOW() LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('si', $tokenHash, $taskId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $access = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $access ? ['user_id' => (int)$access['user_id'], 'task_id' => (int)$access['task_id'], 'expires' => strtotime($access['expires_at'])] : null;
}
