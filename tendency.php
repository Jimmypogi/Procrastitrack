<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/lib/procrastination_classifier.php';

$user_id = intval($_SESSION['user_id']);

$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

$classifier = new ProcrastinationClassifier($conn, $user_id);
$result = $classifier->classify($force);
$hasEvidence = !empty($result['has_evidence']);
$moderateMin = ProcrastinationClassifier::MODERATE_MIN;
$highMin = ProcrastinationClassifier::HIGH_MIN;
$scores = $result['factors']['scores'];
$weeklyAnswerCount = (int)($result['factors']['counts']['weekly_checkins'] ?? 0);
$weeklyAnswerScale = ProcrastinationClassifier::SELF_REPORT_SCORES;
$score = (int)$result['score'];
$level = $score >= $highMin ? 'High' : ($score >= $moderateMin ? 'Moderate' : 'Low');
$levelClass = 'tendency-level-' . strtolower($level);
$factors = [
    'missed_deadlines' => 'Missed deadlines',
    'task_postponement' => 'Task postponements',
    'high_priority_delays' => 'High-priority task delays',
    'self_reported' => 'Self-reported procrastination',
];
$weeks = [];
$hasHistory = false;
$latestHistoryScore = null;
$patterns = [];
$weeklyChange = null;
if ($hasEvidence) {
    $currentWeek = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->modify('monday this week');
    $firstWeek = $currentWeek->modify('-3 weeks');
    $history = ProcrastinationClassifier::getWeeklyHistory($conn, $user_id, $firstWeek, $currentWeek->modify('+1 week'));
    for ($offset = 0; $offset < 4; $offset++) {
        $week = $firstWeek->modify("+$offset weeks");
        $savedScore = $history[$week->format('Y-m-d')] ?? null;
        $x = 12.5 + $offset * 25;
        $weeks[] = ['label' => $week->format('M j'), 'score' => $savedScore, 'x' => $x];
        $hasHistory = $hasHistory || $savedScore !== null;
        $latestHistoryScore = $savedScore ?? $latestHistoryScore;
    }
    if ($weeks[2]['score'] !== null && $weeks[3]['score'] !== null) {
        $weeklyChange = $weeks[3]['score'] - $weeks[2]['score'];
    }
    foreach ([
        'missed_deadlines' => 'At least half of assessed tasks missed their deadlines.',
        'task_postponement' => 'At least half of recorded tasks were postponed more than once.',
        'high_priority_delays' => 'At least half of assessed high-priority tasks missed their deadlines.',
        'self_reported' => 'Weekly check-ins show that you procrastinate at least sometimes on average.',
    ] as $key => $pattern) {
        if (($scores[$key] ?? 0) >= 50) {
            $patterns[] = $pattern;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Procrastination Tendency — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/tendency.css?v=20261125-12">
<link rel="stylesheet" href="assets/css/insights-tabs.css?v=20261124">
</head>
<body class="student-body tendency-page">
<?php include 'includes/sidebar.php'; ?>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">STUDY HABITS</div>
<h1>Your habits at a glance</h1>
<p>A simple estimate based on activity you’ve logged in ProcrastiTrack.</p>
</div>
<div class="s-head-actions">
<a href="tendency.php?refresh=1" class="s-btn">Refresh estimate</a>
<a href="checkin.php#weeklyCheckin" class="s-btn">Weekly check-in</a>
</div>
</header>
<?php include __DIR__ . '/includes/insights_tabs.php'; ?>
<p class="tendency-method-note">This is an estimate, not a grade or diagnosis. It may be incomplete if you have only logged a little activity.</p>

<?php if (!$hasEvidence): ?>
<section class="s-panel tendency-empty-state" aria-labelledby="tendencyEmptyHeading">
    <span class="tendency-empty-icon" aria-hidden="true"><i class="app-icon" data-lucide="chart-no-axes-combined"></i></span>
    <div class="s-label">YOUR FIRST SNAPSHOT</div>
    <h2 id="tendencyEmptyHeading">A little activity is needed first</h2>
    <p>Complete a weekly check-in or record a task outcome to build your estimate.</p>
    <div class="tendency-empty-actions">
        <a class="s-btn primary" href="checkin.php#weeklyCheckin">Do a weekly check-in</a>
        <a class="s-btn" href="tasks.php">View my tasks</a>
    </div>
</section>
<?php else: ?>
<section class="s-panel tendency-live" aria-labelledby="tendencyLiveHeading">
    <header class="tendency-live-header">
        <div>
            <h2 id="tendencyLiveHeading">My procrastination tendency</h2>
            <p>Updated <?php echo date('M j, Y, g:i A', strtotime($result['classified_at'])); ?></p>
        </div>
        <span aria-hidden="true"><i class="app-icon" data-lucide="chart-no-axes-combined"></i></span>
    </header>
    <div class="tendency-live-summary">
        <p>Your current tendency level</p>
        <strong class="tendency-live-level <?php echo $levelClass; ?>"><?php echo $level; ?></strong>
        <div class="tendency-live-score"><?php echo $score; ?> / 100</div>
        <div class="tendency-live-scale">
            <div class="tendency-live-track" aria-hidden="true" style="--score:<?php echo $score; ?>%">
                <div class="tendency-threshold-bar" style="grid-template-columns:<?php echo $moderateMin; ?>fr <?php echo $highMin - $moderateMin; ?>fr <?php echo 100 - $highMin; ?>fr">
                    <span></span><span></span><span></span>
                </div>
            </div>
            <ul class="tendency-live-labels" style="grid-template-columns:<?php echo $moderateMin; ?>fr <?php echo $highMin - $moderateMin; ?>fr <?php echo 100 - $highMin; ?>fr">
                <li>Low <small>0–<?php echo $moderateMin - 1; ?></small></li>
                <li>Moderate <small><?php echo $moderateMin; ?>–<?php echo $highMin - 1; ?></small></li>
                <li>High <small><?php echo $highMin; ?>–100</small></li>
            </ul>
        </div>
        <p><?php echo htmlspecialchars($result['description'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <div class="tendency-live-factors">
        <h3>Factors affecting your tendency</h3>
        <dl>
            <?php foreach ($factors as $key => $label):
                $value = $scores[$key] ?? null;
                $factorLevel = $value >= $highMin ? 'high' : ($value >= $moderateMin ? 'moderate' : 'low');
            ?>
            <div class="tendency-live-factor tendency-level-<?php echo $factorLevel; ?>">
                <dt><?php echo $label; ?></dt>
                <dd>
                    <?php if ($value === null): ?>
                        <span>No data yet</span>
                    <?php else: ?>
                        <strong><?php echo round($value, 1); ?>%</strong>
                        <span class="tendency-factor-track" aria-hidden="true"><span style="width:<?php echo $value; ?>%"></span></span>
                        <?php if ($key === 'self_reported'): ?>
                            <div class="tendency-factor-explanation">
                                <?php $answer = $weeklyAnswerCount === 1 ? array_search($value, $weeklyAnswerScale) : false; ?>
                                <?php if ($answer !== false): ?>
                                    <p>You answered <strong><?php echo $answer; ?></strong> in your weekly check-in. That answer counts as <strong><?php echo $weeklyAnswerScale[$answer]; ?>%</strong> for this factor.</p>
                                <?php else: ?>
                                    <p><strong><?php echo round($value, 1); ?>%</strong> is the average of your answers about how often you procrastinate in <?php echo $weeklyAnswerCount; ?> weekly check-ins.</p>
                                    <details>
                                        <summary>How answers are scored</summary>
                                        <ul>
                                            <?php foreach ($weeklyAnswerScale as $choice => $percentage): ?>
                                                <li><?php echo $choice; ?> <strong><?php echo $percentage; ?>%</strong></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </dd>
            </div>
            <?php endforeach; ?>
        </dl>
    </div>
    <div class="tendency-live-followup">
        <h3>Tendency history</h3>
        <?php if ($hasHistory): ?>
        <figure class="tendency-history-figure">
            <div class="tendency-history-key">
                <span>Latest saved score</span>
                <strong><?php echo $latestHistoryScore; ?> / 100</strong>
            </div>
            <div class="tendency-history-plot" aria-hidden="true">
                <?php foreach ([100, 75, 50, 25, 0] as $tick): ?>
                    <div class="tendency-history-gridline<?php echo $tick === 0 ? ' tendency-history-baseline' : ''; ?>" style="top:<?php echo 100 - $tick; ?>%"><span><?php echo $tick; ?></span></div>
                <?php endforeach; ?>
                <svg viewBox="0 0 100 100" preserveAspectRatio="none">
                    <?php for ($index = 1; $index < count($weeks); $index++):
                        $previous = $weeks[$index - 1];
                        $current = $weeks[$index];
                        $missing = $previous['score'] === null || $current['score'] === null;
                    ?>
                        <line<?php echo $missing ? ' class="tendency-history-placeholder"' : ''; ?> x1="<?php echo $previous['x']; ?>" y1="<?php echo 100 - ($previous['score'] ?? 0); ?>" x2="<?php echo $current['x']; ?>" y2="<?php echo 100 - ($current['score'] ?? 0); ?>" vector-effect="non-scaling-stroke"/>
                    <?php endfor; ?>
                </svg>
                <?php foreach ($weeks as $week): ?>
                    <span class="tendency-history-point<?php echo $week['score'] === null ? ' tendency-history-point-missing' : ''; ?>" style="left:<?php echo $week['x']; ?>%;top:<?php echo 100 - ($week['score'] ?? 0); ?>%"></span>
                <?php endforeach; ?>
            </div>
            <figcaption>
                <ul>
                    <?php foreach ($weeks as $week): ?>
                    <li><span><?php echo $week['label']; ?></span><strong<?php echo $week['score'] === null ? ' class="tendency-history-missing"' : ''; ?>><?php echo $week['score'] === null ? 'No score' : $week['score'] . ' / 100'; ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <p>Weeks labeled “No score” appear at 0 on the chart.</p>
            </figcaption>
        </figure>
        <?php endif; ?>
        <p class="tendency-weekly-change<?php echo $weeklyChange !== null && $weeklyChange < 0 ? ' tendency-level-low' : ''; ?>">
            <?php if ($weeklyChange === null): ?>
                A week-to-week comparison appears when both this week and last week have saved scores.
            <?php elseif ($weeklyChange === 0): ?>
                Your score stayed the same compared with last week.
            <?php else: ?>
                Your score <?php echo $weeklyChange < 0 ? 'decreased' : 'increased'; ?> by <?php echo abs($weeklyChange); ?> <?php echo abs($weeklyChange) === 1 ? 'point' : 'points'; ?> compared with last week.
            <?php endif; ?>
        </p>
    </div>
    <div class="tendency-live-followup">
        <h3>Identified behavioral patterns</h3>
        <?php if ($patterns): ?>
            <ul><?php foreach ($patterns as $pattern): ?><li><?php echo htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul>
        <?php else: ?>
            <p>None of the available factors reached 50%.</p>
        <?php endif; ?>
    </div>
    <div class="tendency-live-followup">
        <h3>Personalized recommendations</h3>
        <ul>
            <?php foreach ($result['recommendations'] as $recommendation): ?>
                <li><?php echo htmlspecialchars($recommendation, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>


</div>
</main>

</body>
</html>
