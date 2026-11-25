<?php
require_once __DIR__ . '/task_deadline.php';

function student_weekly_coach_metrics(mysqli $conn, int $userId, DateTimeImmutable $weekStart): array {
    $from = $weekStart->modify('-7 days')->format('Y-m-d');
    $until = $weekStart->format('Y-m-d');
    $tasks = $conn->prepare("SELECT COUNT(*), COUNT(CASE WHEN status='Completed' AND completed_at < ? THEN 1 END)
        FROM tasks WHERE user_id=? AND deadline>=? AND deadline<?
        AND (status<>'Completed' OR completed_at IS NOT NULL)");
    $tasks->bind_param('siss', $until, $userId, $from, $until);
    $tasks->execute();
    [$due, $completed] = array_map('intval', $tasks->get_result()->fetch_row());
    $tasks->close();

    $changes = $conn->prepare("SELECT COUNT(*) FROM task_deadline_history
        WHERE user_id=? AND changed_at>=? AND changed_at<? AND new_deadline>old_deadline");
    $changes->bind_param('iss', $userId, $from, $until);
    $changes->execute();
    $postponements = (int)$changes->get_result()->fetch_row()[0];
    $changes->close();
    return [
        'completion' => $due ? (int)round(100 * $completed / $due) : null,
        'overdue' => $due ? $due - $completed : null,
        'postponements' => $due || $postponements ? $postponements : null,
    ];
}

function student_ranked_tasks(array $tasks): array {
    $active = array_values(array_filter($tasks, fn($task) => ($task['status'] ?? '') !== 'Completed'));
    $rank = ['High' => 0, 'Medium' => 1, 'Low' => 2];
    usort($active, function($a, $b) use ($rank) {
        return (($rank[$a['priority']] ?? 3) <=> ($rank[$b['priority']] ?? 3))
            ?: (($a['deadline'] ?: '9999-12-31') <=> ($b['deadline'] ?: '9999-12-31'))
            ?: (($a['deadline_time'] ?? '23:59:59') <=> ($b['deadline_time'] ?? '23:59:59'))
            ?: ((int)$a['id'] <=> (int)$b['id']);
    });
    return $active;
}

function student_suggestions(array $tasks, ?array $checkin, DateTimeImmutable $today, ?DateTimeImmutable $now = null, bool $actionableOnly = false): array {
    $today = $today->setTime(0, 0);
    $active = student_ranked_tasks($tasks);
    $first = $active[0] ?? null;
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $overdue = array_filter($active, fn($task) => app_task_is_overdue($task['deadline'] ?? null, $task['deadline_time'] ?? null, $now));
    $soon = array_filter($active, fn($task) => !empty($task['deadline']) && !app_task_is_overdue($task['deadline'], $task['deadline_time'] ?? null, $now) && $task['deadline'] >= $today->format('Y-m-d') && $task['deadline'] <= $today->modify('+2 days')->format('Y-m-d'));
    $lowEnergy = $checkin && $checkin['energy_level'] !== null && (int)$checkin['energy_level'] <= 3;
    $lowFocus = $checkin && $checkin['focus_level'] !== null && (int)$checkin['focus_level'] <= 3;
    $deadlineText = count($overdue) ? count($overdue) . ' unfinished task(s) are overdue. Review the earliest deadline and choose your next action.' : (count($soon) ? count($soon) . ' unfinished task(s) are due today or within two days. Reserve time for them first.' : 'No unfinished tasks are due within two days. Check upcoming deadlines before planning your week.');
    $suggestions = [
        'deadline' => ['title' => 'Deadline alerts', 'text' => $deadlineText, 'basis' => 'Based on unfinished task deadlines.', 'url' => 'tasks.php', 'action' => 'Review deadlines'],
        'priority' => ['title' => 'Priority suggestion', 'text' => $first ? 'Consider starting with "' . $first['title'] . '". Choose one small action you can finish next.' : 'Add a task so AI can assign its priority and help you choose what to work on first.', 'basis' => 'Sorted by AI-assigned priority, then deadline.', 'url' => $first ? 'view_task.php?id=' . (int)$first['id'] : 'add_task.php', 'action' => $first ? 'Open suggested task' : 'Add a task'],
        'energy' => ['title' => 'Break planning', 'text' => $lowEnergy ? 'Your latest check-in reported low energy. Consider a short rest before your next study session, then reassess.' : 'Plan a pause between study blocks. Choose a break length that helps you return ready to study.', 'basis' => $lowEnergy ? 'Based on your latest check-in in the past seven days.' : 'A general planning suggestion.', 'url' => 'study_sessions.php#pomodoroTimer', 'action' => 'Choose timer durations'],
        'focus' => ['title' => 'Focus prompt', 'text' => $lowFocus ? 'Your latest check-in reported low focus. Pick one small action, remove a distraction, and choose a manageable focus duration.' : 'Try a focus-and-break cycle on one task. You can choose and adjust both durations anytime.', 'basis' => $lowFocus ? 'Based on your latest check-in in the past seven days.' : 'Use the adjustable focus timer at your own pace.', 'url' => 'study_sessions.php#pomodoroTimer', 'action' => 'Open focus timer'],
        'planning' => ['title' => 'Time blocking', 'text' => $first ? 'Reserve an available slot for "' . $first['title'] . '". Include a break and leave room for unexpected work.' : 'Choose your available study days and hours, then plan a realistic weekly routine.', 'basis' => 'Use your availability to request a weekly plan below.', 'url' => '#coachForm', 'action' => 'Plan my week']
    ];
    if ($actionableOnly) {
        $signals = array_filter(['deadline' => $overdue || $soon, 'energy' => $lowEnergy, 'focus' => $lowFocus]);
        $suggestions = array_intersect_key($suggestions, $signals);
    }
    return array_values($suggestions);
}
