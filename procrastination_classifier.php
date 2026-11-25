<?php
require_once __DIR__ . '/completion_deadline.php';
require_once __DIR__ . '/task_deadline.php';
require_once __DIR__ . '/checkin_support.php';

/** Estimate procrastination tendency from four recorded behavior indicators. */

class ProcrastinationClassifier
{
    private $conn;
    private $user_id;
    private $cacheMinutes = 30;
    const MODEL_VERSION = 5;
    const MODERATE_MIN = 30;
    const HIGH_MIN = 60;
    const SELF_REPORT_SCORES = ['Never' => 0, 'Rarely' => 25, 'Sometimes' => 50, 'Often' => 75, 'Always' => 100];
    const WEIGHTS = [
        'missed_deadlines' => 35,
        'task_postponement' => 25,
        'high_priority_delays' => 20,
        'self_reported' => 20,
    ];

    /** Tendency tier definitions. */
    const TIERS = [
        'very_low' => [
            'level'   => 1,
            'label'   => 'Few Delay Signals',
            'color'   => '#16a34a',
            'bg'      => '#dcfce7',
            'icon'    => 'green-circle',
            'desc'    => 'Your logged activity currently shows few delay signals. Keep using the routines that work for you.',
        ],
        'low' => [
            'level'   => 2,
            'label'   => 'Occasional Delay Signals',
            'color'   => '#4ade80',
            'bg'      => '#dcfce7',
            'icon'    => 'yellow-circle',
            'desc'    => 'Some of your logged activity suggests occasional delays. Small adjustments may help.',
        ],
        'moderate' => [
            'level'   => 3,
            'label'   => 'Some Delay Signals',
            'color'   => '#f59e0b',
            'bg'      => '#fef3c7',
            'icon'    => 'orange-circle',
            'desc'    => 'Your logged activity suggests delays may be affecting some tasks. Small habits may help.',
        ],
        'high' => [
            'level'   => 4,
            'label'   => 'Frequent Delay Signals',
            'color'   => '#f97316',
            'bg'      => '#fffbeb',
            'icon'    => 'red-circle',
            'desc'    => 'Your logged activity shows recurring delay signals. Structured approaches may be useful.',
        ],
        'very_high' => [
            'level'   => 5,
            'label'   => 'Elevated Delay Signals',
            'color'   => '#dc2626',
            'bg'      => '#fef2f2',
            'icon'    => 'darkred-circle',
            'desc'    => 'Your logged activity shows elevated delay signals. Consider trying focused support strategies.',
        ],
    ];


    public function __construct($conn, $user_id)
    {
        $this->conn = $conn;
        $this->user_id = intval($user_id);
    }

    /**
     * Run a full classification for the user.
     * Returns a result array describing the tendency, score, and factors.
     */
    public function classify($force = false)
    {
        $cached = $this->getCachedResult();

        if (!$force && $cached !== null && ($cached['model_version'] ?? 0) === self::MODEL_VERSION) {
            // Refresh display copy for cached results created with older labels.
            if (isset(self::TIERS[$cached['tier_key'] ?? ''])) {
                $tier = self::TIERS[$cached['tier_key']];
                $cached['label'] = $tier['label'];
                $cached['description'] = $tier['desc'];
                $cached['estimate_note'] = 'This is an estimate based on the behavior data you have logged. It may be incomplete or inaccurate, especially when there is limited data, and it is not a diagnosis.';
            }
            return $cached;
        }

        $factors = $this->extractFactors();
        $available = array_filter($factors['scores'], static fn($score) => $score !== null);
        $weights = array_intersect_key(self::WEIGHTS, $available);
        $weightTotal = array_sum($weights);
        $score = $this->weightedScore($available, $weights);
        $tierKey = $this->scoreToTier($score);
        $recommendations = $this->generateRecommendations($tierKey, $factors);

        $result = [
            'model_version'  => self::MODEL_VERSION,
            'user_id'        => $this->user_id,
            'score'          => $score,
            'tier_key'       => $tierKey,
            'label'          => self::TIERS[$tierKey]['label'],
            'level'          => self::TIERS[$tierKey]['level'],
            'color'          => self::TIERS[$tierKey]['color'],
            'bg'             => self::TIERS[$tierKey]['bg'],
            'icon'           => self::TIERS[$tierKey]['icon'],
            'description'    => self::TIERS[$tierKey]['desc'],
            'estimate_note'  => 'This is an estimate based on the behavior data you have logged. It may be incomplete or inaccurate, especially when there is limited data, and it is not a diagnosis.',
            'factors'        => $factors,
            'weights'        => array_map(static fn($weight) => $weight / $weightTotal, $weights),
            'recommendations' => $recommendations,
            'classified_at'  => date('Y-m-d H:i:s'),
        ];

        $hasEvidence = !empty($available);
        $result['has_evidence'] = $hasEvidence;
        if (!$hasEvidence) {
            $result['label'] = 'Not enough data';
            $result['description'] = 'Add study sessions, check-ins, or task outcomes before interpreting a tendency level.';
            $result['recommendations'] = ['Complete a weekly check-in or record a task outcome to build your estimate.'];
            return $result;
        }
        $this->storeResult($result);
        return $result;
    }

    /**
     * Read cached result if it exists and is recent.
     */
    private function getCachedResult()
    {
        $sql = "SELECT * FROM user_tendency 
                WHERE user_id = ? 
                AND classified_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                ORDER BY classified_at DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $this->user_id, $this->cacheMinutes);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return json_decode($row['result_json'], true);
        }
        return null;
    }

    /**
     * Persist classification result.
     */
    private function storeResult($result)
    {
        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tierKey = $result['tier_key'];
        $score = $result['score'];

        // Update user-level cached column if it exists
        $colsRes = $this->conn->query("SHOW COLUMNS FROM users LIKE 'procrastination_tendency'");
        if ($colsRes && mysqli_num_rows($colsRes) > 0) {
            $this->conn->query(
                "UPDATE users SET procrastination_tendency = '$tierKey' WHERE id = {$this->user_id}"
            );
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO user_tendency 
             (user_id, tendency_level, score, result_json, classified_at) 
             VALUES (?, ?, ?, ?, NOW()) 
             ON DUPLICATE KEY UPDATE 
             tendency_level = VALUES(tendency_level), 
             score = VALUES(score), 
             result_json = VALUES(result_json), 
             classified_at = NOW()"
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isis', $this->user_id, $tierKey, $score, $json);
        $stmt->execute();
        $stmt->close();

        // Append to history table for trend tracking
        $histStmt = $this->conn->prepare(
            "INSERT INTO tendency_history (user_id, tendency_level, score, result_json, created_at) 
             VALUES (?, ?, ?, ?, NOW())"
        );
        if ($histStmt) {
            $histStmt->bind_param('isis', $this->user_id, $tierKey, $score, $json);
            $histStmt->execute();
            $histStmt->close();
        }
    }

    /**
     * Extract behavioral signals from the database for the given user.
     */
    private function extractFactors()
    {
        $uid = $this->user_id;
        $history = [];
        $extensions = [];
        $events = $this->conn->query("SELECT id, task_id, user_id, old_deadline, new_deadline, changed_at, change_reason
            FROM task_deadline_history WHERE user_id = $uid");
        while ($event = $events->fetch_assoc()) {
            $taskId = (int)$event['task_id'];
            $history[$taskId][] = $event;
            if ($event['old_deadline'] !== null && $event['new_deadline'] > $event['old_deadline']) {
                $extensions[$taskId] = ($extensions[$taskId] ?? 0) + 1;
            }
        }

        $taskCount = $repeated = $assessed = $missed = $highAssessed = $highMissed = 0;
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $tasks = $this->conn->query("SELECT id, user_id, status, priority, deadline, deadline_time,
            completed_at, completion_deadline, completion_deadline_known FROM tasks WHERE user_id = $uid");
        while ($task = $tasks->fetch_assoc()) {
            $taskCount++;
            $repeated += ($extensions[$task['id']] ?? 0) >= 2 ? 1 : 0;
            if ($task['status'] === 'Completed') {
                $deadline = task_completion_deadline($task, $history[$task['id']] ?? []);
                if (!$deadline['known'] || $deadline['deadline'] === null) {
                    continue;
                }
                // Completion history preserves a date only.
                $late = substr($task['completed_at'], 0, 10) > $deadline['deadline'];
            } else {
                if (!app_task_is_overdue($task['deadline'], $task['deadline_time'], $now)) {
                    continue;
                }
                $late = true;
            }

            $assessed++;
            $missed += (int)$late;
            if ($task['priority'] === 'High') {
                $highAssessed++;
                $highMissed += (int)$late;
            }
        }

        $frequencies = self::SELF_REPORT_SCORES;
        $reflectionScores = [];
        $table = $this->conn->query("SHOW TABLES LIKE 'checkin_reflections'");
        if ($table->num_rows > 0) {
            $reflections = $this->conn->query("SELECT answers FROM checkin_reflections WHERE user_id = $uid");
            while ($reflection = $reflections->fetch_assoc()) {
                $answers = json_decode($reflection['answers'], true);
                $answer = is_array($answers) ? checkin_answer($answers, 'weekly_procrastination') : '';
                if (isset($frequencies[$answer])) {
                    $reflectionScores[] = $frequencies[$answer];
                }
            }
        }

        return ['counts' => [
            'missed_deadlines' => $missed,
            'task_postponement' => $repeated,
            'high_priority_delays' => $highMissed,
            'weekly_checkins' => count($reflectionScores),
        ], 'scores' => [
            'missed_deadlines' => $assessed ? 100 * $missed / $assessed : null,
            'task_postponement' => ($assessed || $extensions) && $taskCount ? 100 * $repeated / $taskCount : null,
            'high_priority_delays' => $highAssessed ? 100 * $highMissed / $highAssessed : null,
            'self_reported' => $reflectionScores ? array_sum($reflectionScores) / count($reflectionScores) : null,
        ]];
    }

    /**
     * Map a 0-100 score to a tendency tier key.
     */
    private function weightedScore(array $scores, array $weights): int
    {
        if (!$weights) {
            return 0;
        }
        $total = 0;
        foreach ($weights as $key => $weight) {
            $total += $scores[$key] * $weight;
        }
        return (int)round($total / array_sum($weights));
    }

    private function scoreToTier($score)
    {
        if ($score < 15) return 'very_low';
        if ($score < self::MODERATE_MIN) return 'low';
        if ($score < self::HIGH_MIN) return 'moderate';
        if ($score < 70) return 'high';
        return 'very_high';
    }

    /**
     * Generate personalized recommendations based on tier + factors.
     */
    private function generateRecommendations($tierKey, $factors)
    {
        $recs = [];

        // Top tier-specific advice
        if ($tierKey === 'very_low') {
            $recs[] = "Outstanding! Your productivity habits are strong. Keep logging data to maintain awareness.";
        } elseif ($tierKey === 'low') {
            $recs[] = "You're doing well overall. Address small friction points to move toward consistent achiever.";
        } elseif ($tierKey === 'moderate') {
            $recs[] = "Build a daily 15-minute focused block for your highest-priority task. Small wins compound.";
        } elseif ($tierKey === 'high') {
            $recs[] = "Try a focus-and-break cycle with durations you choose. Adjust the timer to suit your workload.";
        } else {
            $recs[] = "Start tomorrow with one 10-minute task. Progress, not perfection. We'll help you build momentum daily.";
        }

        if (($factors['scores']['missed_deadlines'] ?? 0) >= 50) {
            $recs[] = 'Review your upcoming deadlines and break the next task into smaller steps.';
        }
        if (($factors['scores']['task_postponement'] ?? 0) >= 50) {
            $recs[] = 'Review tasks you have rescheduled several times and set aside time for one of them.';
        }
        if (($factors['scores']['self_reported'] ?? 0) >= 50) {
            $recs[] = 'Use your weekly check-in to identify what got in the way and plan one adjustment.';
        }

        return $recs;
    }

    /**
     * Static helper: classify a user without instantiation.
     */
    public static function classifyUser($conn, $user_id, $force = false)
    {
        $classifier = new self($conn, $user_id);
        return $classifier->classify($force);
    }

    /**
     * Get the most recent comparable estimate in each of the last four calendar weeks.
     */
    public static function getWeeklyHistory($conn, int $user_id, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $sql = "SELECT score, created_at
                FROM tendency_history
                WHERE user_id = ? AND created_at >= ? AND created_at < ?
                  AND JSON_VALID(result_json)
                  AND JSON_EXTRACT(result_json, '$.model_version') = ?
                ORDER BY created_at DESC, id DESC";
        $stmt = $conn->prepare($sql);
        $from = $start->format('Y-m-d H:i:s');
        $until = $end->format('Y-m-d H:i:s');
        $version = self::MODEL_VERSION;
        $stmt->bind_param('issi', $user_id, $from, $until, $version);
        $stmt->execute();
        $res = $stmt->get_result();
        $history = [];
        while ($row = $res->fetch_assoc()) {
            $week = (new DateTimeImmutable($row['created_at'], $start->getTimezone()))->modify('monday this week')->format('Y-m-d');
            $history[$week] ??= (int)$row['score'];
        }
        $stmt->close();
        return $history;
    }
}
