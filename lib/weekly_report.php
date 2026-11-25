<?php
/**
 * ProcrastiTrack Weekly Report Engine
 *
 * Generates a comprehensive weekly productivity report for a user.
 * The report aggregates data from tasks, study_sessions, procrastiblock_logs,
 * and check-ins for a given ISO week, computing KPIs, trends, subject
 * breakdowns, and actionable insights.
 *
 * Reports can be generated on-demand (live) or persisted to the
 * weekly_reports table for historical reference.
 */

class WeeklyReport
{
    private $conn;
    private $user_id;

    public function __construct($conn, $user_id)
    {
        $this->conn = $conn;
        $this->user_id = intval($user_id);
    }

    /**
     * Generate a report for the given week.
     *
     * @param string|null $weekStart  Y-m-d for Monday of the target week. Null = current week.
     * @param bool        $persist     Whether to store the report in the DB.
     * @return array
     */
    public function generate($weekStart = null, $persist = true)
    {
        $zone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $zone);
        if ($weekStart === null) $weekStart = $today->modify('monday this week')->format('Y-m-d');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, $zone);
        if (!$start || $start->format('Y-m-d') !== $weekStart || $start->format('N') !== '1' || $start > $today) {
            throw new InvalidArgumentException('Choose a valid Monday from the current or an earlier week.');
        }
        $weekEnd = $start->modify('+6 days')->format('Y-m-d');
        $uid = $this->user_id;

        $report = [
            'user_id'        => $uid,
            'week_start'     => $weekStart,
            'week_end'       => $weekEnd,
            'generated_at'   => date('Y-m-d H:i:s'),
            'is_partial'     => $weekEnd >= $today->format('Y-m-d'),
        ];

        // ---- Daily buckets (Mon–Sun) ----
        $days = [];
        $dailyTasksDue = [];
        $dailyCompleted = [];
        $dailyCreated = [];
        $dailyDelays = [];
        $dailyStudyMin = [];
        $dailyCheckins = [];
        $dailyFocus = [];
        $dailyEnergy = [];
        $tasksDueByDay = [];

        $dueTasksRes = $this->conn->query(
            "SELECT deadline, COUNT(*) total FROM tasks WHERE user_id=$uid AND deadline >= '$weekStart' AND deadline <= '$weekEnd' GROUP BY deadline"
        );
        if ($dueTasksRes) {
            while ($row = mysqli_fetch_assoc($dueTasksRes)) {
                $tasksDueByDay[$row['deadline']] = (int)$row['total'];
            }
        }

        for ($d = 0; $d <= 6; $d++) {
            $date = date('Y-m-d', strtotime($weekStart . " +$d days"));
            $dayName = date('D j', strtotime($date));

            $days[] = $dayName;
            $dailyTasksDue[] = $tasksDueByDay[$date] ?? 0;
            $dailyCompleted[] = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND status='Completed' AND DATE(completed_at)='$date'");
            $dailyCreated[] = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND DATE(created_at)='$date'");
            $dailyDelays[] = (int)$this->scalar("SELECT COUNT(*) FROM procrastiblock_logs WHERE user_id=$uid AND log_type='delay' AND DATE(created_at)='$date'");
            $dailyStudyMin[] = (int)$this->scalar("SELECT COALESCE(SUM(duration_minutes),0) FROM study_sessions WHERE user_id=$uid AND session_date='$date'");
            $dailyCheckins[] = (int)$this->scalar("SELECT COUNT(*) FROM procrastiblock_logs WHERE user_id=$uid AND log_type='checkin' AND DATE(created_at)='$date'");

            $focusRow = $this->scalar("SELECT AVG(focus_level) FROM procrastiblock_logs WHERE user_id=$uid AND DATE(created_at)='$date' AND log_type='checkin' AND focus_level IS NOT NULL");
            $energyRow = $this->scalar("SELECT AVG(energy_level) FROM procrastiblock_logs WHERE user_id=$uid AND DATE(created_at)='$date' AND log_type='checkin' AND energy_level IS NOT NULL");
            $dailyFocus[] = $focusRow !== null ? round((float)$focusRow, 1) : null;
            $dailyEnergy[] = $energyRow !== null ? round((float)$energyRow, 1) : null;
        }

        $report['daily'] = compact(
            'days', 'dailyTasksDue', 'dailyCompleted', 'dailyCreated', 'dailyDelays',
            'dailyStudyMin', 'dailyCheckins', 'dailyFocus', 'dailyEnergy'
        );

        // ---- Aggregate KPIs ----
        $report['tasks'] = $this->taskStats($uid, $weekStart, $weekEnd);
        $report['study'] = $this->studyStats($uid, $weekStart, $weekEnd);
        $report['delays'] = $this->delayStats($uid, $weekStart, $weekEnd);
        $report['checkins'] = $this->checkinStats($uid, $weekStart, $weekEnd);

        // ---- Subject breakdown ----
        $report['subjects'] = $this->subjectBreakdown($uid, $weekStart, $weekEnd);
        $report['behavior_trends'] = $this->behaviorTrends($uid, $weekStart, $weekEnd);

        // ---- Productivity score (0-100) ----
        $report['has_activity'] = array_sum($dailyCreated) + array_sum($dailyCompleted) + array_sum($dailyCheckins) + $report['study']['sessions'] + $report['delays']['count'] > 0;
        $report['has_scored_activity'] = array_sum($dailyCreated) + array_sum($dailyCompleted) + $report['checkins']['count'] + $report['study']['sessions'] > 0;
        $report['score_possible'] = 75;
        $report['productivity_score'] = $this->computeProductivityScore($report);

        // ---- Insights ----
        $report['insights'] = $this->generateInsights($report);

        // ---- Persist if requested ----
        if ($persist) {
            $this->storeReport($report);
        }

        return $report;
    }

    private function taskStats($uid, $weekStart, $weekEnd)
    {
        // Use one cohort for every KPI: tasks whose deadlines fall in this week.
        // This keeps the completion-rate numerator and denominator comparable.
        $total = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND deadline >= '$weekStart' AND deadline <= '$weekEnd'");
        $completed = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND status='Completed' AND deadline >= '$weekStart' AND deadline <= '$weekEnd'");
        $pending = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND status='Pending' AND deadline >= '$weekStart' AND deadline <= '$weekEnd'");
        $inProgress = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND status='In Progress' AND deadline >= '$weekStart' AND deadline <= '$weekEnd'");
        $overdue = (int)$this->scalar("SELECT COUNT(*) FROM tasks WHERE user_id=$uid AND status!='Completed' AND deadline < '$weekStart'");

        $bySubject = [];
        $subjRes = $this->conn->query("SELECT subject, COUNT(*) as total, SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed FROM tasks WHERE user_id=$uid AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59' GROUP BY subject");
        while ($row = mysqli_fetch_assoc($subjRes)) {
            $bySubject[] = $row;
        }

        $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        return [
            'total'       => $total,
            'completed'   => $completed,
            'pending'     => $pending,
            'in_progress' => $inProgress,
            'overdue'     => $overdue,
            'rate'        => $rate,
            'by_subject'  => $bySubject,
        ];
    }

    private function studyStats($uid, $weekStart, $weekEnd)
    {
        $res = $this->scalar("SELECT COALESCE(SUM(duration_minutes),0) as total FROM study_sessions WHERE user_id=$uid AND session_date >= '$weekStart' AND session_date <= '$weekEnd'");
        $sessions = (int)$this->scalar("SELECT COUNT(*) FROM study_sessions WHERE user_id=$uid AND session_date >= '$weekStart' AND session_date <= '$weekEnd'");
        $avgSession = $sessions > 0 ? round((int)$res / $sessions) : 0;

        $elapsedDays = max(1, min(7, (int)(new DateTimeImmutable($weekStart))->diff(new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('%r%a') + 1));
        $dailyAvg = (int)$res / $elapsedDays;

        return [
            'total_minutes' => (int)$res,
            'total_hours'   => round((int)$res / 60, 1),
            'sessions'      => $sessions,
            'avg_session'   => $avgSession,
            'daily_avg'     => round($dailyAvg, 1),
        ];
    }

    private function delayStats($uid, $weekStart, $weekEnd)
    {
        $count = (int)$this->scalar("SELECT COUNT(*) FROM procrastiblock_logs WHERE user_id=$uid AND log_type='delay' AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59'");
        $minutes = (int)$this->scalar("SELECT COALESCE(SUM(duration_minutes),0) FROM procrastiblock_logs WHERE user_id=$uid AND log_type='delay' AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59'");
        $avgProc = $this->scalar("SELECT AVG(procrastination_level) FROM procrastiblock_logs WHERE user_id=$uid AND log_type='delay' AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59'");
        $avgProc = $avgProc !== null ? round((float)$avgProc, 1) : 0;

        // Top delay reasons
        $reasons = [];
        $reasonRes = $this->conn->query("SELECT reason, COUNT(*) as cnt FROM procrastiblock_logs WHERE user_id=$uid AND log_type='delay' AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59' AND reason IS NOT NULL GROUP BY reason ORDER BY cnt DESC LIMIT 5");
        while ($row = mysqli_fetch_assoc($reasonRes)) {
            $reasons[] = ['reason' => $row['reason'], 'count' => (int)$row['cnt']];
        }

        return [
            'count'           => $count,
            'minutes_lost'    => $minutes,
            'hours_lost'      => round($minutes / 60, 1),
            'avg_procrast'    => $avgProc,
            'top_reasons'     => $reasons,
        ];
    }

    private function checkinStats($uid, $weekStart, $weekEnd)
    {
        $weeklyFilter = "user_id=$uid AND log_type='checkin' AND notes LIKE 'Weekly:%' AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59'";
        $count = (int)$this->scalar("SELECT COUNT(*) FROM procrastiblock_logs WHERE $weeklyFilter");
        $avgFocus = $this->scalar("SELECT AVG(focus_level) FROM procrastiblock_logs WHERE $weeklyFilter AND focus_level IS NOT NULL");
        $avgEnergy = $this->scalar("SELECT AVG(energy_level) FROM procrastiblock_logs WHERE $weeklyFilter AND energy_level IS NOT NULL");

        $moodRes = $this->conn->query("SELECT mood, COUNT(*) as cnt FROM procrastiblock_logs WHERE $weeklyFilter AND mood IS NOT NULL GROUP BY mood ORDER BY cnt DESC");
        $moodDist = [];
        while ($row = mysqli_fetch_assoc($moodRes)) {
            $moodDist[] = ['mood' => $row['mood'], 'count' => (int)$row['cnt']];
        }

        return [
            'days'       => (int)$this->scalar("SELECT COUNT(DISTINCT DATE(created_at)) FROM procrastiblock_logs WHERE $weeklyFilter"),
            'count'      => $count,
            'avg_focus'  => $avgFocus !== null ? round((float)$avgFocus, 1) : null,
            'avg_energy' => $avgEnergy !== null ? round((float)$avgEnergy, 1) : null,
            'mood_dist'  => $moodDist,
        ];
    }

    private function subjectBreakdown($uid, $weekStart, $weekEnd)
    {
        $subjects = [];
        $addSubject = function ($name) use (&$subjects) {
            $key = ($name === null || trim($name) === '') ? 'Unassigned' : $name;
            if (!isset($subjects[$key])) {
                $subjects[$key] = ['subject' => $key, 'total_tasks' => 0, 'completed_tasks' => 0, 'completion_rate' => 0, 'study_minutes' => 0, 'delays' => 0];
            }
            return $key;
        };

        // Aggregate independently so multiple sessions and delay logs cannot
        // multiply each other through a multi-table join.
        $res = $this->conn->query("SELECT subject, COUNT(*) total_tasks, SUM(status='Completed') completed_tasks FROM tasks WHERE user_id=$uid AND deadline >= '$weekStart' AND deadline <= '$weekEnd' GROUP BY subject");
        if ($res) while ($row = mysqli_fetch_assoc($res)) {
            $key = $addSubject($row['subject']);
            $subjects[$key]['total_tasks'] = (int)$row['total_tasks'];
            $subjects[$key]['completed_tasks'] = (int)$row['completed_tasks'];
            $subjects[$key]['completion_rate'] = $subjects[$key]['total_tasks'] ? round($subjects[$key]['completed_tasks'] / $subjects[$key]['total_tasks'] * 100) : 0;
        }

        $res = $this->conn->query("SELECT COALESCE(NULLIF(TRIM(t.subject),''),NULLIF(TRIM(ss.subject),'')) subject, COALESCE(SUM(ss.duration_minutes),0) minutes FROM study_sessions ss LEFT JOIN tasks t ON t.id=ss.task_id AND t.user_id=ss.user_id WHERE ss.user_id=$uid AND ss.session_date >= '$weekStart' AND ss.session_date <= '$weekEnd' GROUP BY subject");
        if ($res) while ($row = mysqli_fetch_assoc($res)) {
            $key = $addSubject($row['subject']);
            $subjects[$key]['study_minutes'] += (int)$row['minutes'];
        }

        $res = $this->conn->query("SELECT t.subject, COUNT(*) delays FROM procrastiblock_logs pl LEFT JOIN tasks t ON t.id=pl.task_id AND t.user_id=pl.user_id WHERE pl.user_id=$uid AND pl.log_type='delay' AND pl.created_at >= '$weekStart 00:00:00' AND pl.created_at <= '$weekEnd 23:59:59' GROUP BY t.subject");
        if ($res) while ($row = mysqli_fetch_assoc($res)) {
            $key = $addSubject($row['subject']);
            $subjects[$key]['delays'] = (int)$row['delays'];
        }

        $subjects = array_values($subjects);
        usort($subjects, function ($a, $b) { return $b['study_minutes'] <=> $a['study_minutes']; });
        return $subjects;
    }

    private function behaviorTrends($uid, $weekStart, $weekEnd)
    {
        $trends = [
            'deadline_changes' => ['changes' => 0, 'tasks' => 0, 'repeated_tasks' => 0, 'examples' => []],
            'idle_periods' => ['count' => 0, 'longest_days' => 0, 'gaps' => []],
            'delays_by_subject' => [],
        ];

        $table = $this->conn->query("SHOW TABLES LIKE 'task_deadline_history'");
        if ($table && mysqli_num_rows($table) > 0) {
            $res = $this->conn->query("SELECT COUNT(*) changes, COUNT(DISTINCT task_id) tasks FROM task_deadline_history WHERE user_id=$uid AND old_deadline IS NOT NULL AND new_deadline > old_deadline AND changed_at >= '$weekStart 00:00:00' AND changed_at <= '$weekEnd 23:59:59'");
            if ($res && ($row = mysqli_fetch_assoc($res))) {
                $trends['deadline_changes']['changes'] = (int)$row['changes'];
                $trends['deadline_changes']['tasks'] = (int)$row['tasks'];
            }
            $res = $this->conn->query("SELECT t.title, COUNT(*) changes FROM task_deadline_history h JOIN tasks t ON t.id=h.task_id AND t.user_id=h.user_id WHERE h.user_id=$uid AND h.old_deadline IS NOT NULL AND h.new_deadline > h.old_deadline AND h.changed_at >= '$weekStart 00:00:00' AND h.changed_at <= '$weekEnd 23:59:59' GROUP BY h.task_id, t.title HAVING COUNT(*) > 1 ORDER BY changes DESC LIMIT 5");
            if ($res) while ($row = mysqli_fetch_assoc($res)) {
                $trends['deadline_changes']['repeated_tasks']++;
                $trends['deadline_changes']['examples'][] = ['title' => $row['title'], 'changes' => (int)$row['changes']];
            }
        }

        $events = [];
        $res = $this->conn->query("SELECT created_at activity_at FROM procrastiblock_logs WHERE user_id=$uid AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59' UNION SELECT created_at activity_at FROM study_sessions WHERE user_id=$uid AND created_at >= '$weekStart 00:00:00' AND created_at <= '$weekEnd 23:59:59' ORDER BY activity_at ASC");
        if ($res) while ($row = mysqli_fetch_assoc($res)) $events[] = $row['activity_at'];
        for ($i = 1; $i < count($events); $i++) {
            $gapDays = (strtotime($events[$i]) - strtotime($events[$i - 1])) / 86400;
            if ($gapDays >= 2) {
                $gapDays = round($gapDays, 1);
                $trends['idle_periods']['count']++;
                $trends['idle_periods']['longest_days'] = max($trends['idle_periods']['longest_days'], $gapDays);
                $trends['idle_periods']['gaps'][] = ['from' => $events[$i - 1], 'to' => $events[$i], 'days' => $gapDays];
            }
        }
        usort($trends['idle_periods']['gaps'], function ($a, $b) { return $b['days'] <=> $a['days']; });
        $trends['idle_periods']['gaps'] = array_slice($trends['idle_periods']['gaps'], 0, 5);

        $res = $this->conn->query("SELECT COALESCE(NULLIF(TRIM(t.subject),''),'Unassigned') subject, COUNT(*) delays, ROUND(AVG(pl.procrastination_level),1) avg_procrastination FROM procrastiblock_logs pl LEFT JOIN tasks t ON t.id=pl.task_id AND t.user_id=pl.user_id WHERE pl.user_id=$uid AND pl.log_type='delay' AND pl.created_at >= '$weekStart 00:00:00' AND pl.created_at <= '$weekEnd 23:59:59' GROUP BY subject ORDER BY delays DESC");
        if ($res) while ($row = mysqli_fetch_assoc($res)) {
            $trends['delays_by_subject'][] = ['subject' => $row['subject'], 'count' => (int)$row['delays'], 'avg_procrastination' => $row['avg_procrastination'] !== null ? (float)$row['avg_procrastination'] : null];
        }
        return $trends;
    }

    private function computeProductivityScore($report)
    {
        if (empty($report['has_scored_activity'])) return 0;
        $score = 0;

        // Task completion (0-30)
        $t = $report['tasks'];
        if ($t['total'] > 0) {
            $score += min(30, $t['rate'] / 100 * 30);
        }

        // Study activity (0-25)
        $s = $report['study'];
        $studyMin = $s['total_minutes'];
        if ($studyMin >= 600) $score += 25;
        elseif ($studyMin >= 300) $score += 15;
        elseif ($studyMin >= 60) $score += 8;

        // A weekly reflection is one check-in opportunity per report period.
        $c = $report['checkins'];
        if ($c['count'] > 0) $score += 20;

        // Delay logging is evidence for insights, not a reward or penalty.
        return (int)round(min(100, $score / 75 * 100));
    }

    private function generateInsights($report)
    {
        if (empty($report['has_activity'])) return [['icon' => '', 'severity' => 'info', 'title' => 'No activity recorded', 'message' => 'No study sessions, task activity, delays, or check-ins were recorded for this week. Record an activity to start building your weekly summary.']];
        $insights = [];

        // Completion insight
        $t = $report['tasks'];
        if ($t['total'] > 0) {
            if ($t['rate'] >= 80) {
                $insights[] = ['icon' => '🎯', 'severity' => 'success', 'title' => 'Strong Completion', 'message' => "You completed {$t['completed']} of {$t['total']} tasks due this week ({$t['rate']}%). Your consistency is paying off."];
            } elseif ($t['rate'] >= 50) {
                $insights[] = ['icon' => '📈', 'severity' => 'info', 'title' => 'Moderate Completion', 'message' => "{$t['completed']} of {$t['total']} tasks due this week completed ({$t['rate']}%). Review the unfinished tasks to choose a next step."];
            } else {
                $insights[] = ['icon' => '⚠️', 'severity' => 'warning', 'title' => 'Low Completion', 'message' => "Only {$t['rate']}% of tasks due this week are completed. Try the 2-minute rule for quick tasks."];
            }
        }

        // Overdue insight
        if ($t['overdue'] > 0) {
            $insights[] = ['icon' => '📅', 'severity' => 'warning', 'title' => 'Overdue Items', 'message' => "{$t['overdue']} task(s) are past their deadline. Prioritize these today."];
        }

        // Study time insight
        $s = $report['study'];
        if ($s['total_hours'] >= 10) {
            $insights[] = ['icon' => '📚', 'severity' => 'success', 'title' => 'Deep Study Week', 'message' => "{$s['total_hours']} study hours logged across {$s['sessions']} sessions. Excellent dedication."];
        } elseif ($s['total_hours'] >= 3) {
            $insights[] = ['icon' => '📖', 'severity' => 'info', 'title' => 'Good Study Activity', 'message' => "{$s['total_hours']} study hours logged this week. Aim for 10h for optimal progress."];
        } elseif ($s['total_hours'] > 0) {
            $insights[] = ['icon' => '🍅', 'severity' => 'warning', 'title' => 'Low Study Time', 'message' => "Only {$s['total_hours']}h studied this week. Schedule daily 1-hour blocks."];
        } else {
            $insights[] = ['icon' => '🔔', 'severity' => 'info', 'title' => 'No Study Logged', 'message' => "No study sessions recorded. Start with a focus session using a duration you choose."];
        }

        // Delay insight
        $d = $report['delays'];
        if ($d['count'] > 5) {
            $insights[] = ['icon' => '⏸️', 'severity' => 'warning', 'title' => 'Frequent Delays', 'message' => "{$d['count']} delays logged. Identifying patterns can help reduce them."];
        } elseif ($d['count'] > 0) {
            $insights[] = ['icon' => '⏯️', 'severity' => 'info', 'title' => 'Some Delays', 'message' => "{$d['count']} delay(s) logged ({$d['hours_lost']}h lost). Track triggers to improve."];
        }

        // Focus insight
        $c = $report['checkins'];
        if ($c['count'] > 0) {
            if ($c['avg_focus'] >= 7) {
                $insights[] = ['icon' => '💡', 'severity' => 'success', 'title' => 'Sharp Focus', 'message' => "Average focus {$c['avg_focus']}/10. Your mental clarity is strong this week."];
            } elseif ($c['avg_focus'] >= 4) {
                $insights[] = ['icon' => '⚡', 'severity' => 'info', 'title' => 'Steady Focus', 'message' => "Average focus {$c['avg_focus']}/10. Short breaks can help boost concentration."];
            } elseif ($c['avg_focus'] > 0) {
                $insights[] = ['icon' => '😴', 'severity' => 'warning', 'title' => 'Low Focus', 'message' => "Average focus {$c['avg_focus']}/10. Check sleep and minimize distractions."];
            }
        }

        // Check-in consistency
        if ($c['count'] === 0 && $t['total'] > 0) {
            $insights[] = ['icon' => '📝', 'severity' => 'info', 'title' => 'No Weekly Reflection', 'message' => "Try the weekly check-in to reflect on your study habits and choose a next step."];
        }

        // Subject-specific insights
        foreach ($report['subjects'] as $subj) {
            if ($subj['delays'] >= 3 && $subj['completion_rate'] < 50) {
                $insights[] = ['icon' => '🎯', 'severity' => 'warning', 'title' => $subj['subject'] . ' Challenge', 'message' => "High delays ({$subj['delays']}) and low completion ({$subj['completion_rate']}%) in {$subj['subject']}. Try scheduling specific time blocks."];
            }
        }

        if (empty($insights)) {
            $insights[] = ['icon' => '✅', 'severity' => 'success', 'title' => 'All Clear', 'message' => 'No major issues detected this week. Keep building momentum!'];
        }

        return $insights;
    }

    private function storeReport($report)
    {
        // Check if table exists
        $tblCheck = $this->conn->query("SHOW TABLES LIKE 'weekly_reports'");
        if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) {
            return;
        }

        $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $score = $report['productivity_score'];
        $stmt = $this->conn->prepare(
            "INSERT INTO weekly_reports (user_id, week_start, week_end, score, report_json, generated_at) 
             VALUES (?, ?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
             week_end = VALUES(week_end), 
             score = VALUES(score), 
             report_json = VALUES(report_json), 
             generated_at = VALUES(generated_at)"
        );
        if (!$stmt) return;
        $stmt->bind_param('ississ', $this->user_id, $report['week_start'], $report['week_end'], $score, $json, $report['generated_at']);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Retrieve a stored weekly report.
     */
    public static function getStoredReport($conn, $user_id, $weekStart = null)
    {
        if ($weekStart === null) {
            $weekStart = date('Y-m-d', strtotime('monday this week'));
        }

        $stmt = $conn->prepare("SELECT report_json, generated_at FROM weekly_reports WHERE user_id = ? AND week_start = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('is', $user_id, $weekStart);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return json_decode($row['report_json'], true);
        }
        $stmt->close();
        return null;
    }

    /**
     * Get list of weeks that have stored reports.
     */
    public static function getReportHistory($conn, $user_id, $limit = 12)
    {
        $limit = (int)$limit;
        $stmt = $conn->prepare("SELECT week_start, week_end, score, generated_at FROM weekly_reports WHERE user_id = ? ORDER BY week_start DESC LIMIT $limit");
        if (!$stmt) return [];
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private function scalar($sql)
    {
        $res = $this->conn->query($sql);
        if (!$res) return null;
        $row = mysqli_fetch_row($res);
        return $row[0] ?? null;
    }
}
