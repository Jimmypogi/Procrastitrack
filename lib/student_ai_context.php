<?php
function student_ai_context(mysqli $conn, int $uid): array {
    // The authenticated user ID comes from the PHP session, never from the AI request.
    $account = ['name' => null];
    $accountStmt = $conn->prepare('SELECT fullname FROM users WHERE id=? LIMIT 1');
    if ($accountStmt) {
        $accountStmt->bind_param('i', $uid);
        if ($accountStmt->execute()) {
            $accountRow = $accountStmt->get_result()->fetch_assoc();
            if ($accountRow) $account['name'] = trim((string)($accountRow['fullname'] ?? '')) ?: null;
        }
        $accountStmt->close();
    }
    $queries=[
      'active_tasks'=>"SELECT id,title,subject,deadline,deadline_time,priority,status FROM tasks WHERE user_id=? AND status<>'Completed' ORDER BY deadline IS NULL,deadline,deadline_time,id LIMIT 40",
      'recent_completed_tasks'=>"SELECT id,title,deadline,completed_at FROM tasks WHERE user_id=? AND status='Completed' AND completed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY completed_at DESC LIMIT 40",
      'recent_delays'=>"SELECT task_id,reason,duration_minutes,created_at FROM procrastiblock_logs WHERE user_id=? AND log_type='delay' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 30",
      'recent_checkins'=>"SELECT focus_level,energy_level,mood,reason,notes,created_at FROM procrastiblock_logs WHERE user_id=? AND log_type='checkin' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 14",
      'recent_study'=>"SELECT task_id,subject,duration_minutes,session_date,start_time FROM study_sessions WHERE user_id=? AND COALESCE(session_date,DATE(created_at))>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 30"
    ];
    $data=['account'=>$account,'today'=>date('Y-m-d'),'timezone'=>'Asia/Manila','window'=>'Last 30 days; active tasks include all dates','limitations'=>['Lists are capped, not complete lifetime histories.','No recorded activity is not proof of procrastination.','Difficulty and estimated effort are unknown unless supplied by the student.','Current deadlines may have been edited; do not treat completion versus current deadline as verified historical lateness.']];
    foreach($queries as $key=>$sql) {
        $stmt=$conn->prepare($sql); $stmt->bind_param('i',$uid); $stmt->execute();
        $data[$key]=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
    return $data;
}

function student_ai_pattern_summary(mysqli $conn, int $uid): array {
    $summary = [
        'activity_gaps' => ['minimum_gap_days' => 2, 'gap_count' => 0, 'average_gap_days' => null, 'longest_gap_days' => null, 'meaning' => 'Gaps describe missing activity records, not confirmed inactivity.'],
        'repeated_deadline_extensions' => [],
        'subject_delay_trends' => [],
    ];

    require_once __DIR__ . '/activity_gaps.php';
    $activitySql = "SELECT created_at, log_type FROM procrastiblock_logs WHERE user_id=? UNION ALL SELECT TIMESTAMP(COALESCE(session_date, DATE(created_at)), COALESCE(end_time, start_time, '00:00:00')) AS created_at, 'study' AS log_type FROM study_sessions WHERE user_id=? UNION ALL SELECT created_at, 'task created' AS log_type FROM tasks WHERE user_id=? UNION ALL SELECT completed_at AS created_at, 'task completed' AS log_type FROM tasks WHERE user_id=? AND completed_at IS NOT NULL";
    $activityStmt = $conn->prepare($activitySql);
    if ($activityStmt) {
        $activityStmt->bind_param('iiii', $uid, $uid, $uid, $uid);
        if ($activityStmt->execute()) {
            $entries = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $gaps = activity_gaps($entries, time(), 2);
            if ($gaps) {
                $summary['activity_gaps']['gap_count'] = count($gaps);
                $summary['activity_gaps']['average_gap_days'] = round(array_sum(array_column($gaps, 'days')) / count($gaps), 1);
                $summary['activity_gaps']['longest_gap_days'] = $gaps[0]['days'];
            }
        }
        $activityStmt->close();
    }

    // Count only tasks whose deadline was moved later at least twice, then
    // summarize those repeated extensions by subject for the AI.
    $extensionSql = "SELECT repeated.subject, COUNT(*) AS affected_tasks, SUM(repeated.extensions) AS extensions, MAX(repeated.extensions) AS max_extensions_per_task FROM (SELECT COALESCE(NULLIF(TRIM(t.subject),''),'Unassigned') AS subject, t.id, COUNT(h.id) AS extensions FROM tasks t INNER JOIN task_deadline_history h ON h.task_id=t.id AND h.user_id=t.user_id WHERE t.user_id=? AND h.old_deadline IS NOT NULL AND h.new_deadline>h.old_deadline GROUP BY t.id, COALESCE(NULLIF(TRIM(t.subject),''),'Unassigned') HAVING COUNT(h.id)>=2) AS repeated GROUP BY repeated.subject ORDER BY extensions DESC LIMIT 12";
    $extensionStmt = $conn->prepare($extensionSql);
    if ($extensionStmt) {
        $extensionStmt->bind_param('i', $uid);
        if ($extensionStmt->execute()) $summary['repeated_deadline_extensions'] = $extensionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $extensionStmt->close();
    }

    $subjectSql = "SELECT COALESCE(NULLIF(TRIM(t.subject),''),'Unassigned') AS subject, COUNT(DISTINCT t.id) AS delayed_tasks, (SELECT COUNT(*) FROM tasks st WHERE st.user_id=t.user_id AND COALESCE(NULLIF(TRIM(st.subject),''),'Unassigned')=COALESCE(NULLIF(TRIM(t.subject),''),'Unassigned')) AS total_tasks FROM tasks t INNER JOIN procrastiblock_logs pl ON pl.task_id=t.id AND pl.user_id=t.user_id AND pl.log_type='delay' WHERE t.user_id=? GROUP BY COALESCE(NULLIF(TRIM(t.subject),''),'Unassigned') HAVING COUNT(DISTINCT t.id)>=2 ORDER BY delayed_tasks DESC LIMIT 10";
    $subjectStmt = $conn->prepare($subjectSql);
    if ($subjectStmt) {
        $subjectStmt->bind_param('i', $uid);
        if ($subjectStmt->execute()) $summary['subject_delay_trends'] = $subjectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $subjectStmt->close();
    }

    return $summary;
}
