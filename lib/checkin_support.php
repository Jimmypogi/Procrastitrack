<?php
function checkin_support_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS checkin_reflections (
        log_id INT NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        answers TEXT NOT NULL,
        checkin_type VARCHAR(10) DEFAULT NULL,
        period_start DATE DEFAULT NULL,
        recommendation TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_reflection_user (user_id, created_at),
        UNIQUE KEY uniq_checkin_period (user_id, checkin_type, period_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [
        'checkin_type' => 'VARCHAR(10) DEFAULT NULL AFTER answers',
        'period_start' => 'DATE DEFAULT NULL AFTER checkin_type',
        'recommendation' => 'TEXT DEFAULT NULL AFTER period_start',
    ];
    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM checkin_reflections LIKE '$column'");
        if ($result->num_rows === 0) {
            $conn->query("ALTER TABLE checkin_reflections ADD COLUMN $column $definition");
        }
    }

    $index = $conn->query("SHOW INDEX FROM checkin_reflections WHERE Key_name='uniq_checkin_period'");
    if ($index->num_rows === 0) {
        $conn->query('ALTER TABLE checkin_reflections ADD UNIQUE KEY uniq_checkin_period (user_id, checkin_type, period_start)');
    }
}

function checkin_questions(): array {
    return [
        'Daily' => [
            'daily_task_completion' => [
                'question' => 'How did studying go for you today?',
                'options' => ['Went well', 'Made some progress', 'Was difficult', 'I haven’t studied today'],
            ],
            'daily_focus' => [
                'question' => 'How focused were you while studying today?',
                'options' => ['Very Focused', 'Somewhat Focused', 'Not Focused'],
            ],
            'daily_distraction' => [
                'question' => 'What was your biggest distraction today?',
                'options' => ['Social Media', 'Mobile Games', 'Watching Videos', 'Friends', 'Sleeping', 'Household Chores', 'Other'],
            ],
            'daily_study_hours' => [
                'question' => 'Approximately how many hours did you spend studying today?',
                'options' => ['Less than 1 hour', '1–2 hours', '3–4 hours', 'More than 4 hours'],
            ],
            'daily_productivity' => [
                'question' => 'How productive do you feel today?',
                'options' => ['Very Productive', 'Productive', 'Neutral', 'Unproductive', 'Very Unproductive'],
            ],
            'daily_difficulty' => [
                'question' => 'Is there anything that made studying difficult today?',
                'type' => 'textarea',
                'required' => false,
            ],
        ],
        'Weekly' => [
            'weekly_procrastination' => [
                'question' => 'How often did you procrastinate this week?',
                'options' => ['Never', 'Rarely', 'Sometimes', 'Often', 'Always'],
            ],
            'weekly_reason' => [
                'question' => 'What was your biggest reason for procrastinating this week?',
                'options' => ['Lack of motivation', 'Digital distractions (social media, gaming, etc.)', 'Academic workload', 'Poor time management', 'Stress or tiredness', 'Difficulty understanding tasks', 'Personal reasons', 'I did not procrastinate'],
            ],
            'weekly_tasks_on_time' => [
                'question' => 'How often did you complete your tasks on time?',
                'options' => ['Never', 'Rarely', 'Sometimes', 'Often', 'Always', 'I had no tasks this week'],
            ],
            'weekly_time_management' => [
                'question' => 'How well did you manage your time this week?',
                'options' => ['Very poorly', 'Poorly', 'Moderately well', 'Well', 'Very well'],
            ],
            'weekly_motivation' => [
                'question' => 'How motivated were you to complete your tasks this week?',
                'options' => ['Not motivated', 'Slightly motivated', 'Moderately motivated', 'Very motivated', 'Extremely motivated'],
            ],
            'weekly_procrastination_time' => [
                'question' => 'During which time of day did you procrastinate the most?',
                'options' => ['Morning', 'Afternoon', 'Evening', 'Late night', 'No specific time', 'I did not procrastinate'],
            ],
            'weekly_productivity' => [
                'question' => 'How satisfied are you with your productivity this week?',
                'options' => ['Very dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very satisfied'],
            ],
            'weekly_improvement_area' => [
                'question' => 'What would you like to improve next week?',
                'options' => ['Time management', 'Task prioritization', 'Focus and concentration', 'Consistency', 'Reducing distractions', 'Completing tasks on time', 'Maintaining my current productivity'],
            ],
        ],
    ];
}

function checkin_period_bounds(string $type, ?DateTimeImmutable $now = null): array {
    if (!in_array($type, ['Daily', 'Weekly'], true)) {
        throw new InvalidArgumentException('Check-in type must be Daily or Weekly.');
    }
    $timezone = new DateTimeZone('Asia/Manila');
    $today = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
    if ($type === 'Daily') {
        return ['start' => $today->format('Y-m-d'), 'end' => $today->format('Y-m-d')];
    }
    $start = $today->modify('monday this week');
    return ['start' => $start->format('Y-m-d'), 'end' => $start->modify('+6 days')->format('Y-m-d')];
}

function checkin_submission_is_sunday(?DateTimeImmutable $now = null): bool {
    $timezone = new DateTimeZone('Asia/Manila');
    return ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->format('N') === '7';
}

function checkin_next_available_date(?DateTimeImmutable $now = null, bool $submittedThisWeek = false): DateTimeImmutable {
    $timezone = new DateTimeZone('Asia/Manila');
    $today = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
    $daysUntilSunday = 7 - (int)$today->format('N');
    if ($daysUntilSunday === 0 && $submittedThisWeek) {
        $daysUntilSunday = 7;
    }
    return $today->modify('+' . $daysUntilSunday . ' days');
}

function checkin_period_start_for_date(string $type, string $date): string {
    $timezone = new DateTimeZone('Asia/Manila');
    $day = new DateTimeImmutable($date, $timezone);
    return checkin_period_bounds($type, $day)['start'];
}

function checkin_entry_type(array $entry, ?array $answers = null): string {
    $type = $entry['checkin_type'] ?? ($answers['checkin_type'] ?? '');
    if (in_array($type, ['Daily', 'Weekly'], true)) {
        return $type;
    }
    return strpos((string)($entry['notes'] ?? ''), 'Weekly:') === 0 ? 'Weekly' : 'Daily';
}

function checkin_weekly_history_sql(): string {
    return "FROM procrastiblock_logs l
        LEFT JOIN checkin_reflections r ON r.log_id=l.id AND r.user_id=l.user_id
        WHERE l.user_id=? AND l.log_type='checkin'
        AND CASE
            WHEN r.checkin_type IN ('Weekly','Daily') THEN r.checkin_type
            WHEN JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(r.answers), r.answers, '{}'), '$.checkin_type')) IN ('Weekly','Daily')
                THEN JSON_UNQUOTE(JSON_EXTRACT(r.answers, '$.checkin_type'))
            WHEN l.notes LIKE 'Weekly:%' THEN 'Weekly'
            ELSE 'Daily'
        END = 'Weekly'";
}

function checkin_answer(array $answers, string $field): string {
    $answer = $answers['responses'][$field]['answer'] ?? ($answers[$field] ?? '');
    return is_string($answer) ? $answer : '';
}

function checkin_recommendation(array $answers): string {
    $type = $answers['checkin_type'] ?? 'Daily';
    if ($type === 'Weekly') {
        $procrastination = checkin_answer($answers, 'weekly_procrastination');
        $reason = checkin_answer($answers, 'weekly_reason');
        $motivation = checkin_answer($answers, 'weekly_motivation');
        $onTime = checkin_answer($answers, 'weekly_tasks_on_time');
        $timeManagement = checkin_answer($answers, 'weekly_time_management');
        if ($reason === 'Digital distractions (social media, gaming, etc.)') {
            return 'During your usual study time, put your phone out of reach and block your main distracting app for one short focus session.';
        }
        if ($reason === 'Difficulty understanding tasks') {
            return 'Before your next session, write down the first unclear part of one task and ask a teacher, classmate, or tutor for help with that step.';
        }
        if ($reason === 'Stress or tiredness') {
            return 'Choose one manageable task, work in a short focus block, and leave time for a real break before continuing.';
        }
        if ($reason === 'Academic workload' || $reason === 'Poor time management' || in_array($onTime, ['Never', 'Rarely'], true) || in_array($timeManagement, ['Very poorly', 'Poorly'], true)) {
            return 'At the start of next week, choose your three most important tasks and reserve a realistic time slot for the first one.';
        }
        if ($reason === 'Lack of motivation' || in_array($motivation, ['Not motivated', 'Slightly motivated'], true)) {
            return 'Make the first step small enough to start in ten minutes, such as opening the assignment and outlining one part.';
        }
        if ($reason === 'Personal reasons') {
            return 'Set a realistic goal that fits your week and break it into one small action you can do when you have time.';
        }
        if ($reason === 'I did not procrastinate' || $procrastination === 'Never') {
            return 'Keep using the routine that worked this week and plan your first task before the week gets busy.';
        }
        if (in_array($procrastination, ['Often', 'Always'], true)) {
            return 'Choose one task to begin early next week, split it into smaller steps, and schedule the first step.';
        }
        return 'Choose one realistic goal for next week and give it a specific place in your schedule.';
    }

    $studyHours = checkin_answer($answers, 'daily_study_hours');
    $productivity = checkin_answer($answers, 'daily_productivity');
    $focus = checkin_answer($answers, 'daily_focus');
    $distraction = checkin_answer($answers, 'daily_distraction');
    if ($studyHours === 'Less than 1 hour') {
        return 'Start with a daily goal of at least one hour of focused study and gradually increase your study time.';
    }
    if ($distraction === 'Social Media') {
        return 'Try limiting social media while studying. Use a 25-minute Pomodoro session followed by a 5-minute break.';
    }
    if ($productivity === 'Very Productive') {
        return 'Great job! Keep maintaining your current study habits and continue tracking your progress.';
    }
    if (in_array($productivity, ['Unproductive', 'Very Unproductive'], true) || $focus === 'Not Focused') {
        return 'Break large tasks into smaller goals and reward yourself after completing each task.';
    }
    return 'Choose one clear task for your next study session and give it a realistic time slot. Small, steady steps add up.';
}

function checkin_weekly_task_activity(mysqli $conn, int $userId, array $period): array {
    require_once __DIR__ . '/task_deadline.php';
    $timezone = new DateTimeZone('Asia/Manila');
    $start = $period['start'] . ' 00:00:00';
    $endExclusive = (new DateTimeImmutable($period['end'], $timezone))->modify('+1 day')->format('Y-m-d 00:00:00');

    $stmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='Completed' AND completed_at>=? AND completed_at<?");
    $stmt->bind_param('iss', $userId, $start, $endExclusive);
    $stmt->execute();
    $completed = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*), COUNT(DISTINCT task_id) FROM procrastiblock_logs WHERE user_id=? AND log_type='delay' AND created_at>=? AND created_at<?");
    $stmt->bind_param('iss', $userId, $start, $endExclusive);
    $stmt->execute();
    [$delayReports, $delayedTasks] = array_map('intval', $stmt->get_result()->fetch_row());
    $stmt->close();

    $stmt = $conn->prepare("SELECT reason, COUNT(*) total FROM procrastiblock_logs
        WHERE user_id=? AND log_type='delay' AND created_at>=? AND created_at<? AND reason IS NOT NULL AND TRIM(reason)<>''
        GROUP BY reason ORDER BY total DESC, reason ASC LIMIT 3");
    $stmt->bind_param('iss', $userId, $start, $endExclusive);
    $stmt->execute();
    $delayReasons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $overdueSql = "SELECT COUNT(*) FROM tasks t WHERE t.user_id=? AND t.status<>'Completed' AND " . app_task_overdue_sql('t');
    $stmt = $conn->prepare($overdueSql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $overdue = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return [
        'completed_tasks' => $completed,
        'delay_reports' => $delayReports,
        'delayed_tasks' => $delayedTasks,
        'overdue_tasks' => $overdue,
        'delay_reasons' => array_map(static function (array $row): array {
            return ['reason' => (string)$row['reason'], 'count' => (int)$row['total']];
        }, $delayReasons),
    ];
}

function checkin_generate_analysis(mysqli $conn, int $userId, array $answers, array $period): array {
    $activity = checkin_weekly_task_activity($conn, $userId, $period);
    $stmt = $conn->prepare("SELECT r.answers, r.period_start, r.checkin_type, l.notes, l.created_at
        FROM checkin_reflections r
        JOIN procrastiblock_logs l ON l.id=r.log_id AND l.user_id=r.user_id
        WHERE r.user_id=? AND l.log_type='checkin'
          AND COALESCE(r.period_start, DATE(l.created_at))<?
          AND (r.checkin_type='Weekly' OR (r.checkin_type IS NULL AND l.notes LIKE 'Weekly:%'))
        ORDER BY COALESCE(r.period_start, DATE(l.created_at)) DESC, l.created_at DESC LIMIT 4");
    $stmt->bind_param('is', $userId, $period['start']);
    $stmt->execute();
    $previousRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $previous = [];
    foreach ($previousRows as $row) {
        $priorAnswers = json_decode((string)$row['answers'], true);
        if (is_array($priorAnswers)) $previous[] = $priorAnswers;
    }
    $procrastination = checkin_answer($answers, 'weekly_procrastination');
    $reason = checkin_answer($answers, 'weekly_reason');
    $timeOfDay = checkin_answer($answers, 'weekly_procrastination_time');
    if ($reason === 'I did not procrastinate' || $procrastination === 'Never') {
        $summary = 'You reported little or no procrastination this week. ';
    } else {
        $summary = 'You reported procrastinating ' . strtolower($procrastination) . ', mainly because of ' . strtolower($reason) . '. ';
    }
    $summary .= 'You completed tasks on time ' . strtolower(checkin_answer($answers, 'weekly_tasks_on_time')) . ', managed your time ' . strtolower(checkin_answer($answers, 'weekly_time_management')) . ', and felt ' . strtolower(checkin_answer($answers, 'weekly_motivation')) . '. ';
    $summary .= 'You were ' . strtolower(checkin_answer($answers, 'weekly_productivity')) . ' with your productivity. ';
    if ($timeOfDay === 'I did not procrastinate' || $timeOfDay === 'No specific time') {
        $summary .= 'You did not report one main time of day for procrastination. ';
    } else {
        $summary .= 'You most often put things off in the ' . strtolower($timeOfDay) . '. ';
    }
    $summary .= 'Your focus for next week is ' . strtolower(checkin_answer($answers, 'weekly_improvement_area')) . '.';

    $comparison = 'This is your first recorded weekly check-in. Future check-ins will help you compare your habits over time.';
    $taskComparison = '';
    if ($previous) {
        $comparisonParts = [];
        $previousProcrastination = checkin_answer($previous[0], 'weekly_procrastination');
        if ($previousProcrastination !== '') {
            $levels = ['Never' => 0, 'Rarely' => 1, 'Sometimes' => 2, 'Often' => 3, 'Always' => 4];
            if ($previousProcrastination === $procrastination) {
                $comparisonParts[] = 'Your reported procrastination frequency stayed at ' . strtolower($procrastination) . '.';
            } elseif (($levels[$procrastination] ?? 2) < ($levels[$previousProcrastination] ?? 2)) {
                $comparisonParts[] = 'Your reported procrastination frequency improved from ' . strtolower($previousProcrastination) . ' to ' . strtolower($procrastination) . '.';
            } else {
                $comparisonParts[] = 'Your reported procrastination frequency changed from ' . strtolower($previousProcrastination) . ' to ' . strtolower($procrastination) . '.';
            }
        }
        $previousOnTime = checkin_answer($previous[0], 'weekly_tasks_on_time');
        $currentOnTime = checkin_answer($answers, 'weekly_tasks_on_time');
        $onTimeLevels = ['Never' => 0, 'Rarely' => 1, 'Sometimes' => 2, 'Often' => 3, 'Always' => 4];
        if (isset($onTimeLevels[$previousOnTime], $onTimeLevels[$currentOnTime]) && $previousOnTime !== $currentOnTime) {
            $comparisonParts[] = 'On-time completion changed from ' . strtolower($previousOnTime) . ' to ' . strtolower($currentOnTime) . '.';
        }
        if ($comparisonParts) $comparison = implode(' ', $comparisonParts) . ' Compared with your last check-in.';
        $previousActivity = $previous[0]['analysis']['task_activity'] ?? null;
        if (is_array($previousActivity)) {
            $taskComparison = 'Since your previous check-in: ' . $activity['completed_tasks'] . ' tasks completed (previously ' . (int)($previousActivity['completed_tasks'] ?? 0) . '), '
                . $activity['delay_reports'] . ' delay reports (previously ' . (int)($previousActivity['delay_reports'] ?? 0) . '), and '
                . $activity['overdue_tasks'] . ' overdue now (previously ' . (int)($previousActivity['overdue_tasks'] ?? 0) . ').';
        }
    }

    $patternFields = [
        'weekly_procrastination' => 'Procrastination frequency',
        'weekly_reason' => 'Main reason',
        'weekly_procrastination_time' => 'Most common time',
        'weekly_time_management' => 'Time management',
        'weekly_motivation' => 'Motivation',
        'weekly_tasks_on_time' => 'On-time completion',
        'weekly_productivity' => 'Productivity satisfaction',
        'weekly_improvement_area' => 'Improvement focus',
    ];
    $allCheckins = array_merge([$answers], $previous);
    $patterns = [];
    foreach ($patternFields as $field => $label) {
        $counts = [];
        foreach ($allCheckins as $checkin) {
            $value = checkin_answer($checkin, $field);
            if ($value === '' || in_array($value, ['I did not procrastinate', 'No specific time'], true)) continue;
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        if ($counts) {
            arsort($counts);
            $value = (string)array_key_first($counts);
            if ($counts[$value] >= 2) {
                $patterns[] = ['label' => $label, 'value' => $value, 'occurrences' => $counts[$value], 'weeks_observed' => count($allCheckins)];
            }
        }
    }

    $recommendations = [];
    $addRecommendation = static function (string $recommendation) use (&$recommendations): void {
        if (!in_array($recommendation, $recommendations, true) && count($recommendations) < 4) {
            $recommendations[] = $recommendation;
        }
    };
    $addRecommendation(checkin_recommendation($answers));
    if ($activity['overdue_tasks'] > 0) {
        $addRecommendation('Review your ' . $activity['overdue_tasks'] . ' currently overdue ' . ($activity['overdue_tasks'] === 1 ? 'task' : 'tasks') . ' and choose the next small action for the one due soonest.');
    }
    if ($activity['delay_reports'] > 0 && $activity['delay_reasons']) {
        $addRecommendation('Your delay logs mention "' . $activity['delay_reasons'][0]['reason'] . '". Use that as a cue to prepare your study space before starting next week.');
    }
    if (checkin_answer($answers, 'weekly_tasks_on_time') === 'Never' || checkin_answer($answers, 'weekly_tasks_on_time') === 'Rarely') {
        $addRecommendation('Set a reminder for the next deadline and schedule a short first work block at least one day ahead.');
    }
    $improvementActions = [
        'Task prioritization' => 'At the start of next week, rank your tasks by deadline and importance, then begin with the top one.',
        'Focus and concentration' => 'Try one short, distraction-free focus block on a single task before switching activities.',
        'Consistency' => 'Choose a small study block you can repeat on two planned days next week.',
        'Reducing distractions' => 'Silence notifications and keep your phone away for one planned study block.',
        'Completing tasks on time' => 'Break the next task into steps and schedule the first step before its deadline gets close.',
        'Maintaining my current productivity' => 'Keep the routine that worked and plan your first task before next week begins.',
        'Time management' => 'Add one realistic study block to your calendar before the week gets busy.',
    ];
    $improvement = checkin_answer($answers, 'weekly_improvement_area');
    if (isset($improvementActions[$improvement])) $addRecommendation($improvementActions[$improvement]);
    if (!$recommendations) $addRecommendation('Choose one small task for next week and give its first step a specific time.');

    return [
        'summary' => $summary,
        'comparison' => $comparison,
        'task_comparison' => $taskComparison,
        'recurring_patterns' => $patterns,
        'task_activity' => $activity,
        'recommendations' => $recommendations,
    ];
}

function checkin_period_submitted(mysqli $conn, int $userId, string $type, array $period): bool {
    $stmt = $conn->prepare('SELECT log_id FROM checkin_reflections WHERE user_id=? AND checkin_type=? AND period_start=? LIMIT 1');
    $stmt->bind_param('iss', $userId, $type, $period['start']);
    $stmt->execute();
    $alreadySaved = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($alreadySaved) {
        return true;
    }

    $stmt = $conn->prepare("SELECT l.created_at, l.notes, r.answers, r.checkin_type, r.period_start
        FROM procrastiblock_logs l
        LEFT JOIN checkin_reflections r ON r.log_id=l.id AND r.user_id=l.user_id
        WHERE l.user_id=? AND l.log_type='checkin' AND DATE(l.created_at) BETWEEN ? AND ?
        ORDER BY l.created_at DESC, l.id DESC");
    $stmt->bind_param('iss', $userId, $period['start'], $period['end']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($entry = $result->fetch_assoc()) {
        $answers = json_decode((string)($entry['answers'] ?? ''), true);
        $answers = is_array($answers) ? $answers : null;
        if (checkin_entry_type($entry, $answers) !== $type) {
            continue;
        }
        $createdDate = substr((string)$entry['created_at'], 0, 10);
        $entryPeriod = $entry['period_start'] ?: checkin_period_start_for_date($type, $createdDate);
        if ($entryPeriod === $period['start']) {
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

function checkin_productivity_score(string $productivity): ?int {
    $scores = [
        'Very Unproductive' => 1,
        'Unproductive' => 2,
        'Neutral' => 3,
        'Productive' => 4,
        'Very Productive' => 5,
    ];
    return $scores[$productivity] ?? null;
}

function checkin_dashboard_summary(mysqli $conn, int $userId, ?DateTimeImmutable $now = null): array {
    checkin_support_schema($conn);
    $timezone = new DateTimeZone('Asia/Manila');
    $today = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
    $weeklyStart = checkin_period_bounds('Weekly', $today)['start'];
    $weeklyPeriod = checkin_period_bounds('Weekly', $today);
    $weeklyCurrent = null;
    $latest = null;

    $stmt = $conn->prepare("SELECT l.created_at, l.notes, r.answers, r.checkin_type, r.period_start, r.recommendation
        FROM procrastiblock_logs l
        LEFT JOIN checkin_reflections r ON r.log_id=l.id AND r.user_id=l.user_id
        WHERE l.user_id=? AND l.log_type='checkin'
        ORDER BY l.created_at DESC, l.id DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($entry = $result->fetch_assoc()) {
        $answers = json_decode((string)($entry['answers'] ?? ''), true);
        $answers = is_array($answers) ? $answers : [];
        $type = checkin_entry_type($entry, $answers);
        if ($type !== 'Weekly') continue;
        $submittedDate = substr((string)$entry['created_at'], 0, 10);
        $periodStart = $entry['period_start'] ?: checkin_period_start_for_date($type, $submittedDate);
        $item = [
            'type' => $type,
            'date' => $submittedDate,
            'created_at' => $entry['created_at'],
            'recommendation' => trim((string)($entry['recommendation'] ?? $answers['recommendation'] ?? '')),
            'analysis' => is_array($answers['analysis'] ?? null) ? $answers['analysis'] : null,
        ];
        if ($latest === null) {
            if ($item['recommendation'] === '' && $answers) {
                $item['recommendation'] = checkin_guidance($answers)['action'];
            }
            $latest = $item;
        }
        if ($periodStart === $weeklyStart && $weeklyCurrent === null) {
            $weeklyCurrent = $item;
        }
    }
    $stmt->close();

    return [
        'weekly_submitted' => $weeklyCurrent !== null,
        'weekly' => $weeklyCurrent,
        'latest' => $latest,
        'last_submission' => $latest ? date('M j, Y', strtotime($latest['created_at'])) : null,
        'week_start' => $weeklyPeriod['start'],
    ];
}

function checkin_blockers(): array {
    return ['none' => 'Nothing got in the way', 'distractions' => 'Phone or other distractions', 'unclear' => 'I did not know where to start', 'overwhelmed' => 'The task felt too big', 'tired' => 'I felt tired', 'time' => 'Other responsibilities', 'motivation' => 'It was hard to get started', 'other' => 'Something else'];
}

function checkin_guidance(array $answers): array {
    if (isset($answers['responses'])) {
        $type = $answers['checkin_type'] ?? 'Daily';
        $plan = checkin_answer($answers, 'weekly_improvement_area');
        if ($plan === '') $plan = checkin_answer($answers, 'weekly_improvement');
        $reason = checkin_answer($answers, 'weekly_reason');
        return [
            'title' => $type === 'Weekly' ? 'A focus for next week' : 'A small next step for today',
            'basis' => 'Based on your ' . strtolower($type) . ' behavior check-in.',
            'action' => $answers['recommendation'] ?? checkin_recommendation($answers),
            'plan' => $type === 'Weekly' ? $plan : checkin_answer($answers, ''),
            'why' => $type === 'Weekly' ? $reason : checkin_answer($answers, 'daily_difficulty'),
            'url' => $type === 'Weekly' ? 'study_coach.php' : 'study_sessions.php#pomodoroTimer',
            'link' => $type === 'Weekly' ? 'Plan next week' : 'Choose a focus session',
        ];
    }

    $type = $answers['checkin_type'] ?? 'Daily';
    if ($type === 'Weekly') {
        $score = (int)($answers['weekly_productivity'] ?? 0);
        return ['title' => 'Plan one change for next week', 'basis' => $score ? "You rated this week $score/10. Your reflection is a starting point, not a judgement." : 'Based on your weekly reflection.', 'action' => $score >= 7 ? 'Keep one routine that helped this week and reserve a place for it in your next plan.' : 'Choose one obstacle from your reflection and one smaller action you can try next week.', 'plan' => $answers['weekly_goals'] ?? '', 'why' => $answers['weekly_didnt_work'] ?? '', 'url' => 'study_coach.php', 'link' => 'Plan next week'];
    }
    $blocker = $answers['blocker'] ?? '';
    $strategies = [
        'distractions' => ['Make room for one task', 'Put the main distraction out of reach, open only the material you need, and try one small action.'],
        'unclear' => ['Make the first step clear', 'Read the task instructions and write down the first thing you can do. Ask a classmate or teacher about the part that is unclear.'],
        'overwhelmed' => ['Shrink the starting point', 'Choose just one question, paragraph, or example. Finish that small piece before deciding what comes next.'],
        'tired' => ['Leave room to recharge', 'Take a pause if you need one. When you return, choose a lighter task and a focus duration that feels manageable.'],
        'time' => ['Choose a realistic opening', 'Find one available slot around your responsibilities. Choose a task small enough to fit that slot.'],
        'motivation' => ['Start with a small action', 'Open your materials and choose the easiest useful step. You can decide whether to continue after that.'],
        'none' => ['Keep what helped', 'Repeat one thing that made studying easier today. Choose your next task before closing your materials.'],
        'other' => ['Try one change', 'Use the obstacle you described to choose one change you control. Start with a smaller version of your next task.']
    ];
    $basis = isset(checkin_blockers()[$blocker]) ? 'You selected: ' . checkin_blockers()[$blocker] . '.' : 'Based on your recorded focus, energy, and mood.';
    if (!isset($strategies[$blocker])) {
        if (($answers['mood'] ?? '') === 'Distracted') $blocker = 'distractions';
        elseif (isset($answers['energy_score']) && (int)$answers['energy_score'] <= 3) $blocker = 'tired';
        elseif (isset($answers['focus_score']) && (int)$answers['focus_score'] <= 3) $blocker = 'unclear';
        else $blocker = 'other';
        $basis .= ' You have not selected a cause, so this is a general suggestion.';
    }
    [$title, $action] = $strategies[$blocker];
    return ['title' => $title, 'basis' => $basis, 'action' => $action, 'plan' => $answers['next_step'] ?? ($answers['plan_tomorrow'] ?? ''), 'why' => $answers['blocker_detail'] ?? ($answers['challenges'] ?? ''), 'url' => 'study_sessions.php#pomodoroTimer', 'link' => 'Choose a focus session'];
}
