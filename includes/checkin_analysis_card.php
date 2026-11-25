<?php
$checkinAnalysis = is_array($checkinAnalysis ?? null) ? $checkinAnalysis : [];
$taskActivity = is_array($checkinAnalysis['task_activity'] ?? null) ? $checkinAnalysis['task_activity'] : [];
$recommendations = is_array($checkinAnalysis['recommendations'] ?? null) ? $checkinAnalysis['recommendations'] : [];
$analysisHeadingId = 'checkinAnalysisHeading' . (isset($checkinAnalysisId) ? '-' . (int)$checkinAnalysisId : '');
?>
<?php if ($checkinAnalysis): ?>
<section class="ci-card ci-analysis-card" aria-labelledby="<?php echo $analysisHeadingId; ?>">
    <div class="ci-card-heading">
        <div><div class="s-label">YOUR WEEK AT A GLANCE</div><h2 id="<?php echo $analysisHeadingId; ?>">Behavior summary</h2></div>
    </div>
    <p class="ci-analysis-summary"><?php echo htmlspecialchars((string)($checkinAnalysis['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="ci-analysis-stats" aria-label="Task activity this week">
        <div><strong><?php echo (int)($taskActivity['completed_tasks'] ?? 0); ?></strong><span>Tasks completed</span></div>
        <div><strong><?php echo (int)($taskActivity['delayed_tasks'] ?? 0); ?></strong><span>Tasks delayed</span></div>
        <div><strong><?php echo (int)($taskActivity['delay_reports'] ?? 0); ?></strong><span>Delay reports</span></div>
        <div><strong><?php echo (int)($taskActivity['overdue_tasks'] ?? 0); ?></strong><span>Overdue now</span></div>
    </div>
    <?php if (!empty($taskActivity['delay_reasons'])): ?>
        <div class="ci-analysis-detail">
            <h3>Reasons in your delay logs</h3>
            <ul>
                <?php foreach ($taskActivity['delay_reasons'] as $reason): ?>
                    <li><?php echo htmlspecialchars((string)$reason['reason'], ENT_QUOTES, 'UTF-8'); ?> <span>(<?php echo (int)$reason['count']; ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($checkinAnalysis['comparison'])): ?>
        <p class="ci-analysis-comparison"><strong>Compared with your previous check-in:</strong> <?php echo htmlspecialchars((string)$checkinAnalysis['comparison'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if (!empty($checkinAnalysis['task_comparison'])): ?>
        <p class="ci-analysis-comparison"><?php echo htmlspecialchars((string)$checkinAnalysis['task_comparison'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if (!empty($checkinAnalysis['recurring_patterns'])): ?>
        <div class="ci-analysis-detail">
            <h3>Signals repeated across check-ins</h3>
            <ul>
                <?php foreach ($checkinAnalysis['recurring_patterns'] as $pattern): ?>
                    <li><?php echo htmlspecialchars((string)$pattern['label'], ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars((string)$pattern['value'], ENT_QUOTES, 'UTF-8'); ?> <span>(<?php echo (int)$pattern['occurrences']; ?> of <?php echo (int)$pattern['weeks_observed']; ?> check-ins)</span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <div class="ci-analysis-detail">
        <h3>Practical next steps</h3>
        <ul class="ci-analysis-recommendations">
            <?php foreach ($recommendations as $recommendation): ?>
                <li><?php echo htmlspecialchars((string)$recommendation, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <a class="ci-analysis-coach-link" href="study_coach.php?mode=suggestions">Ask Study Coach for AI suggestions <span aria-hidden="true">→</span></a>
</section>
<?php endif; ?>
