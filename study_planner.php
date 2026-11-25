<?php
function study_planner_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS study_plans (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        plan_text TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY user_created(user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS study_blocks (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        task_id INT DEFAULT NULL, title VARCHAR(120) NOT NULL,
        block_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL,
        break_minutes INT NOT NULL DEFAULT 5, notes VARCHAR(1000) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY user_date(user_id,block_date,start_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function study_block_values(array $post, ?string $today = null): array {
    $values=[];
    foreach(['title','block_date','start_time','end_time','break_minutes','notes','task_id'] as $field) {
        $values[$field]=is_string($post[$field]??null)?trim($post[$field]):'';
    }
    $error='';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$values['block_date']);
    $today=$today??(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    if($values['title']===''||mb_strlen($values['title'])>120) $error='Add a study block title of up to 120 characters.';
    elseif(!$date||$date->format('Y-m-d')!==$values['block_date']||$values['block_date']<$today||$values['block_date']>'9999-12-31') $error='Choose a valid date today or later.';
    elseif(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$values['start_time'])||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$values['end_time'])||$values['end_time']<=$values['start_time']) $error='Choose an end time after the start time on the same day.';
    elseif(filter_var($values['break_minutes'],FILTER_VALIDATE_INT)===false||(int)$values['break_minutes']<0) $error='Break minutes must be a whole number of zero or more.';
    elseif(mb_strlen($values['notes'])>1000) $error='Keep study notes within 1,000 characters.';
    elseif($values['task_id']!==''&&(filter_var($values['task_id'],FILTER_VALIDATE_INT)===false||(int)$values['task_id']<1)) $error='Choose a valid task.';
    if($error==='') {
        $minutes=(strtotime($values['end_time'])-strtotime($values['start_time']))/60;
        if($minutes>720) $error='Keep each study block within 12 hours.';
        elseif((int)$values['break_minutes']>=$minutes) $error='The break must be shorter than the study block.';
    }
    return [$values,$error];
}
