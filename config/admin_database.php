<?php

require_once __DIR__.'/database_connection.php';
$conn = app_database_connect();

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `admins` (
    `id` int NOT NULL AUTO_INCREMENT,
    `username` varchar(100) NOT NULL UNIQUE,
    `email` varchar(255) NOT NULL UNIQUE,
    `password` varchar(255) NOT NULL,
    `fullname` varchar(255) DEFAULT NULL,
    `role` enum('superadmin','admin','analyst') DEFAULT 'admin',
    `last_login` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_username` (`username`),
    KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$adminActiveColumn = mysqli_query($conn, "SHOW COLUMNS FROM admins LIKE 'is_active'");
if ($adminActiveColumn && mysqli_num_rows($adminActiveColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `user_procrastination_profiles` (
    `id` int NOT NULL AUTO_INCREMENT,
    `user_id` int NOT NULL,
    `procrastination_level` enum('Very Low','Low','Moderate','High','Very High') DEFAULT 'Low',
    `procrastination_score` decimal(5,2) DEFAULT 0.00,
    `primary_type` varchar(50) DEFAULT NULL,
    `secondary_type` varchar(50) DEFAULT NULL,
    `risk_factors` json DEFAULT NULL,
    `behavioral_patterns` json DEFAULT NULL,
    `classification_method` enum('rules','ml','hybrid') DEFAULT 'hybrid',
    `confidence` decimal(4,2) DEFAULT 0.00,
    `last_analyzed` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user` (`user_id`),
    KEY `idx_level` (`procrastination_level`),
    KEY `idx_score` (`procrastination_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `procrastination_classification_rules` (
    `id` int NOT NULL AUTO_INCREMENT,
    `rule_name` varchar(100) NOT NULL,
    `rule_description` text DEFAULT NULL,
    `metric_name` varchar(50) NOT NULL,
    `operator` enum('>','>=','<','<=','=','!=','between') NOT NULL,
    `threshold_value` decimal(10,2) NOT NULL,
    `threshold_value2` decimal(10,2) DEFAULT NULL,
    `weight` decimal(4,2) DEFAULT 1.00,
    `level_contribution` enum('Very Low','Low','Moderate','High','Very High') DEFAULT 'Low',
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `ml_model_weights` (
    `id` int NOT NULL AUTO_INCREMENT,
    `feature_name` varchar(100) NOT NULL,
    `weight` decimal(6,4) NOT NULL,
    `bias` decimal(6,4) DEFAULT 0.0000,
    `model_version` varchar(20) DEFAULT 'v1.0',
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_feature` (`feature_name`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$defaultAdmin = mysqli_query($conn, "SELECT id FROM admins WHERE username = 'admin'");
if ($defaultAdmin && mysqli_num_rows($defaultAdmin) === 0) {
    $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
    mysqli_query($conn, "INSERT INTO admins (username, email, password, fullname, role) VALUES ('admin', 'admin@procrastitrack.com', '$hashedPassword', 'System Administrator', 'superadmin')");
}

$defaultRules = [
    ['task_overdue_ratio', '>', 0.5, null, 2.00, 'Very High'],
    ['task_overdue_ratio', '>', 0.3, null, 1.50, 'High'],
    ['task_overdue_ratio', '>', 0.15, null, 1.00, 'Moderate'],
    ['avg_delay_duration', '>', 60, null, 1.50, 'High'],
    ['avg_delay_duration', '>', 30, null, 1.00, 'Moderate'],
    ['delay_frequency_per_week', '>', 10, null, 2.00, 'Very High'],
    ['delay_frequency_per_week', '>', 5, null, 1.50, 'High'],
    ['delay_frequency_per_week', '>', 2, null, 1.00, 'Moderate'],
    ['completion_rate', '<', 0.3, null, 2.00, 'Very High'],
    ['completion_rate', '<', 0.5, null, 1.50, 'High'],
    ['completion_rate', '<', 0.7, null, 1.00, 'Moderate'],
    ['study_consistency', '<', 0.3, null, 1.50, 'High'],
    ['study_consistency', '<', 0.5, null, 1.00, 'Moderate'],
    ['procrastination_log_avg', '>', 7, null, 1.50, 'High'],
    ['procrastination_log_avg', '>', 4, null, 1.00, 'Moderate'],
    ['checkin_mood_avg', '<', 3, null, 1.00, 'Moderate'],
    ['checkin_focus_avg', '<', 3, null, 1.00, 'Moderate'],
    ['task_priority_high_ratio', '>', 0.5, null, 1.00, 'Moderate'],
];

foreach ($defaultRules as $rule) {
    $check = mysqli_query($conn, "SELECT id FROM procrastination_classification_rules WHERE metric_name = '{$rule[0]}' AND operator = '{$rule[1]}' AND threshold_value = {$rule[2]}");
    if ($check && mysqli_num_rows($check) === 0) {
        $threshold2 = $rule[3] ? $rule[3] : 'NULL';
        mysqli_query($conn, "INSERT INTO procrastination_classification_rules (rule_name, rule_description, metric_name, operator, threshold_value, threshold_value2, weight, level_contribution) VALUES ('{$rule[0]}_rule', 'Auto-generated rule for {$rule[0]}', '{$rule[0]}', '{$rule[1]}', {$rule[2]}, $threshold2, {$rule[4]}, '{$rule[5]}')");
    }
}

$defaultWeights = [
    'task_overdue_ratio' => 0.2500,
    'avg_delay_duration' => 0.1500,
    'delay_frequency_per_week' => 0.2000,
    'completion_rate' => -0.2000,
    'study_consistency' => -0.1000,
    'procrastination_log_avg' => 0.1000,
    'checkin_mood_avg' => -0.0500,
    'checkin_focus_avg' => -0.0500,
    'task_priority_high_ratio' => 0.0500,
];

foreach ($defaultWeights as $feature => $weight) {
    $check = mysqli_query($conn, "SELECT id FROM ml_model_weights WHERE feature_name = '$feature'");
    if ($check && mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "INSERT INTO ml_model_weights (feature_name, weight, model_version) VALUES ('$feature', $weight, 'v1.0')");
    }
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'role'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN role ENUM('student','admin') DEFAULT 'student' AFTER email");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER role");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_activity'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN last_activity TIMESTAMP NULL DEFAULT NULL AFTER is_active");
}

$cols = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'registration_date'");
if ($cols && mysqli_num_rows($cols) === 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN registration_date DATE DEFAULT NULL AFTER last_activity");
}
?>
