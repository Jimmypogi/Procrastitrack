<?php

// Keep date validation, reports, and database day boundaries on Philippine time.
date_default_timezone_set('Asia/Manila');

require_once __DIR__.'/database_connection.php';
require_once __DIR__.'/../lib/task_deadline.php';
$conn = app_database_connect();

mysqli_query($conn, "SET time_zone = '+08:00'");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `tasks` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `title` varchar(255) NOT NULL,
    `subject` varchar(100) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `deadline` date DEFAULT NULL,
    `priority` enum('Low','Medium','High') DEFAULT 'Medium',
    `status` enum('Pending','In Progress','Completed') DEFAULT 'Pending',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_priority` (`priority`),
    KEY `idx_deadline` (`deadline`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `task_deadline_history` (
    `id` int NOT NULL AUTO_INCREMENT,
    `task_id` int NOT NULL,
    `user_id` int NOT NULL,
    `old_deadline` date DEFAULT NULL,
    `new_deadline` date DEFAULT NULL,
    `change_reason` varchar(255) DEFAULT NULL,
    `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_task_history` (`task_id`),
    KEY `idx_user_history` (`user_id`),
    KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$historyDeadlineColumn = $conn->query("SHOW COLUMNS FROM task_deadline_history LIKE 'new_deadline'")->fetch_assoc();
if ($historyDeadlineColumn['Null'] === 'NO') {
    $conn->query('ALTER TABLE task_deadline_history MODIFY new_deadline DATE DEFAULT NULL');
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `task_email_access_tokens` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `task_id` int NOT NULL,
    `token_hash` char(64) NOT NULL,
    `expires_at` datetime NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_task_email_token_hash` (`token_hash`),
    KEY `idx_task_email_expiry` (`expires_at`),
    KEY `idx_task_email_owner` (`user_id`, `task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "INSERT INTO task_deadline_history (task_id, user_id, old_deadline, new_deadline, change_reason)
        SELECT t.id, t.user_id, NULL, t.deadline, 'Initial deadline (migration)'
        FROM tasks t
        WHERE t.deadline IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM task_deadline_history h WHERE h.task_id=t.id)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `study_sessions` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `task_id` int DEFAULT NULL,
    `subject` varchar(100) DEFAULT NULL,
    `duration_minutes` int NOT NULL,
    `session_date` date DEFAULT NULL,
    `start_time` time DEFAULT NULL,
    `end_time` time DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_task` (`task_id`),
    KEY `idx_date` (`session_date`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `procrastiblock_logs` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `log_type` varchar(30) NOT NULL,
    `task_id` int DEFAULT NULL,
    `reason` text DEFAULT NULL,
    `duration_minutes` int DEFAULT NULL,
    `mood` varchar(50) DEFAULT NULL,
    `focus_level` int DEFAULT NULL,
    `energy_level` int DEFAULT NULL,
    `procrastination_level` int DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_logs` (`user_id`),
    KEY `idx_log_type` (`log_type`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `notifications` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `type` varchar(50) DEFAULT 'info',
    `title` varchar(255) NOT NULL,
    `message` text,
    `link` varchar(255) DEFAULT NULL,
    `priority` enum('low','normal','high') DEFAULT 'normal',
    `meta` json DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_read` (`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `user_tendency` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `tendency_level` varchar(30) NOT NULL,
    `score` int NOT NULL,
    `result_json` json DEFAULT NULL,
    `classified_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user` (`user_id`),
    KEY `idx_score` (`score`),
    KEY `idx_tendency_level` (`tendency_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `tendency_history` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `tendency_level` varchar(30) NOT NULL,
    `score` int NOT NULL,
    `result_json` json DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `weekly_reports` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `week_start` date NOT NULL,
    `week_end` date DEFAULT NULL,
    `score` int DEFAULT 0,
    `report_json` json DEFAULT NULL,
    `generated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_week` (`user_id`, `week_start`),
    KEY `idx_user` (`user_id`),
    KEY `idx_week` (`week_start`),
    KEY `idx_score` (`score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `daily_checkins` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `checkin_date` date NOT NULL,
    `mood` varchar(50) DEFAULT NULL,
    `focus_level` int DEFAULT NULL,
    `energy_level` int DEFAULT NULL,
    `motivation_level` int DEFAULT NULL,
    `study_hours` decimal(4,2) DEFAULT 0.00,
    `distractions` text DEFAULT NULL,
    `procrastination_reason` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_date` (`user_id`, `checkin_date`),
    KEY `idx_user` (`user_id`),
    KEY `idx_date` (`checkin_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `title` varchar(255) NOT NULL,
    `description` text DEFAULT NULL,
    `event_date` date NOT NULL,
    `event_time` time DEFAULT NULL,
    `event_type` enum('task','deadline','study','checkin','reminder') DEFAULT 'task',
    `related_task_id` int DEFAULT NULL,
    `color` varchar(20) DEFAULT '#2563EB',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'completed_at'");
if ($colCheck && mysqli_num_rows($colCheck) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN completed_at TIMESTAMP NULL DEFAULT NULL");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'description'");
// Snapshot future completion deadlines; leave historical records unmodified/unknown.
$completionCols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'completion_deadline'");
if ($completionCols && mysqli_num_rows($completionCols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN completion_deadline DATE DEFAULT NULL");
}
$completionKnownCols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'completion_deadline_known'");
if ($completionKnownCols && mysqli_num_rows($completionKnownCols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN completion_deadline_known TINYINT(1) NOT NULL DEFAULT 0");
}
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN description TEXT DEFAULT NULL AFTER subject");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'status'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN status ENUM('Pending','In Progress','Completed') DEFAULT 'Pending' AFTER priority");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'estimated_duration'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN estimated_duration INT DEFAULT NULL AFTER description");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'deadline_time'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN deadline_time TIME DEFAULT NULL AFTER deadline");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM tasks LIKE 'created_at'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER completed_at");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM study_sessions LIKE 'session_date'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE study_sessions ADD COLUMN session_date DATE DEFAULT NULL AFTER duration_minutes");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM study_sessions LIKE 'start_time'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE study_sessions ADD COLUMN start_time TIME DEFAULT NULL AFTER session_date");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM study_sessions LIKE 'end_time'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE study_sessions ADD COLUMN end_time TIME DEFAULT NULL AFTER start_time");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS task_overdue_email_log (
    user_id int NOT NULL,
    task_id int NOT NULL,
    deadline date NOT NULL,
    sent_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, task_id, deadline),
    KEY idx_overdue_email_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'fullname'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN fullname VARCHAR(255) DEFAULT NULL AFTER id");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER fullname");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'procrastination_tendency'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN procrastination_tendency VARCHAR(30) DEFAULT NULL AFTER email");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'dark_mode'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN dark_mode TINYINT DEFAULT 0 AFTER procrastination_tendency");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_notifications'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN email_notifications TINYINT DEFAULT 1 AFTER dark_mode");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'reminder_notifications'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN reminder_notifications TINYINT DEFAULT 1 AFTER email_notifications");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'language'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN language VARCHAR(10) DEFAULT 'en' AFTER reminder_notifications");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'university'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN university VARCHAR(255) DEFAULT NULL AFTER language");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'major'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN major VARCHAR(255) DEFAULT NULL AFTER university");
}
// Common account metadata is also needed before the first admin visit.
foreach (['registration_date'=>'DATE DEFAULT NULL','last_activity'=>'TIMESTAMP NULL DEFAULT NULL','is_active'=>'TINYINT(1) DEFAULT 1'] as $field=>$definition) {
    $column=$conn->query("SHOW COLUMNS FROM users LIKE '$field'");
    if(!$column->num_rows) $conn->query("ALTER TABLE users ADD COLUMN $field $definition");
}
