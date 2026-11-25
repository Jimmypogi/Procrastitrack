<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/lib/checkin_support.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { require __DIR__ . '/save_checkin.php'; exit(); }

$userId = (int)$_SESSION['user_id'];
$summary = checkin_dashboard_summary($conn, $userId);
$questionsByType = ['Weekly' => checkin_questions()['Weekly']];
$error = $_SESSION['checkin_error'] ?? '';
$values = $_SESSION['checkin_values'] ?? [];
unset($_SESSION['checkin_error'], $_SESSION['checkin_values']);
$savedType = ($_GET['saved'] ?? '') === 'Weekly' ? 'Weekly' : '';
function ci_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$timezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $timezone);
$week = checkin_period_bounds('Weekly', $now);
$isSunday = checkin_submission_is_sunday($now);
$weeklySubmitted = $summary['weekly_submitted'];
$canSubmit = $isSunday && !$weeklySubmitted;
$nextAvailable = checkin_next_available_date($now, $weeklySubmitted);
$daysUntilAvailable = (int)$now->setTime(0, 0)->diff($nextAvailable)->format('%a');
$checkinAnalysis = is_array($summary['weekly']['analysis'] ?? null) ? $summary['weekly']['analysis'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekly Productivity Check-in | ProcrastiTrack</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/checkin.css?v=weekly-checkin-ui-v2">
</head>
<body class="student-body checkin-page">
<?php include 'includes/sidebar.php'; ?>
<main class="s-main">
<div class="s-inner">
<header class="s-head ci-page-header">
    <div><div class="s-label">WEEKLY REFLECTION</div><h1>Weekly Productivity Check-in</h1><p>Reflect on what affected your focus this week. Your answers help you spot habits and choose a practical next step.</p></div>
</header>

<?php if ($error !== ''): ?>
<div role="alert" class="ci-note error"><?php echo ci_escape($error); ?></div>
<?php elseif ($savedType !== ''): ?>
<div role="status" class="ci-note">Your <?php echo strtolower(ci_escape($savedType)); ?> behavior check-in is saved. <a href="checkin_history.php">View your answers and recommendation in check-in history.</a></div>
<?php endif; ?>

<section class="ci-period-card" aria-label="Current check-in period">
    <div><span class="s-label">THIS WEEK · MONDAY–SUNDAY</span><strong><?php echo ci_escape(date('M j', strtotime($week['start']))); ?> – <?php echo ci_escape(date('M j, Y', strtotime($week['end']))); ?></strong></div>
    <div class="ci-period-status">
        <?php if ($weeklySubmitted): ?>
            <strong class="is-submitted">Submitted</strong><span>Next check-in opens Sunday, <?php echo ci_escape($nextAvailable->format('M j')); ?>.</span>
        <?php elseif ($isSunday): ?>
            <strong class="is-open">Available today</strong><span>Submit your reflection before the day ends.</span>
        <?php else: ?>
            <strong>Opens Sunday</strong><span><?php echo ci_escape($nextAvailable->format('M j')); ?> · <?php echo $daysUntilAvailable; ?> <?php echo $daysUntilAvailable === 1 ? 'day' : 'days'; ?> to go.</span>
        <?php endif; ?>
    </div>
</section>

<?php if ($weeklySubmitted): ?>
    <section class="ci-card ci-locked-card" id="weeklyCheckin" aria-labelledby="submittedHeading">
        <div class="ci-card-heading"><div><div class="s-label">WEEKLY REFLECTION</div><h2 id="submittedHeading">You’re all set for this week</h2></div><span class="ci-status-pill">Submitted</span></div>
        <p class="ci-copy">Your answers are saved. You can review this reflection and its recommendations below or in <a href="checkin_history.php">check-in history</a>.</p>
    </section>
    <?php if ($checkinAnalysis): include __DIR__ . '/includes/checkin_analysis_card.php'; ?>
    <?php elseif ($summary['weekly'] && $summary['weekly']['recommendation'] !== ''): ?>
        <section class="ci-card ci-analysis-card"><div class="ci-card-heading"><div><div class="s-label">YOUR NEXT STEP</div><h2>Weekly recommendation</h2></div></div><p class="ci-analysis-summary"><?php echo ci_escape($summary['weekly']['recommendation']); ?></p></section>
    <?php endif; ?>
<?php elseif ($canSubmit): ?>
    <section class="ci-card ci-behavior-card" id="weeklyCheckin" aria-labelledby="weeklyCheckinHeading">
        <div class="ci-card-heading"><div><div class="s-label">REFLECT ON YOUR WEEK</div><h2 id="weeklyCheckinHeading">Weekly Productivity Check-in</h2></div><span class="ci-status-pill ci-status-pill-open">Open today</span></div>
        <p class="ci-copy">Choose one answer for each question. Track your progress as you answer, then review your weekly summary after submitting.</p>
        <?php $questions = $questionsByType['Weekly']; $requiredCount = count($questions); $progressCount = 0;
        foreach ($questions as $field => $_question) if (is_string($values[$field] ?? null) && trim($values[$field]) !== '') $progressCount++;
        ?>
        <form action="save_checkin.php" method="post" class="ci-form" data-checkin-form>
            <?php app_csrf_field(); ?>
            <input type="hidden" name="checkin_type" value="Weekly">
            <div class="ci-progress-wrap">
                <div class="ci-progress-heading"><span>Progress</span><output data-progress-label><?php echo $progressCount; ?> of <?php echo $requiredCount; ?> answered</output></div>
                <progress class="progress ci-progress" value="<?php echo $progressCount; ?>" max="<?php echo $requiredCount; ?>" data-progress aria-label="Weekly check-in completion"></progress>
            </div>
            <div class="ci-fields">
                <?php foreach ($questions as $field => $question): ?>
                    <fieldset class="ci-question ci-choice-group" data-progress-group>
                        <legend><?php echo ci_escape($question['question']); ?></legend>
                        <div class="ci-choice-grid">
                            <?php foreach ($question['options'] as $index => $option):
                                $optionId = $field . '_' . $index;
                                $checked = ($values[$field] ?? '') === $option;
                            ?>
                                <label class="ci-choice-option" for="<?php echo ci_escape($optionId); ?>">
                                    <input type="radio" id="<?php echo ci_escape($optionId); ?>" name="<?php echo ci_escape($field); ?>" value="<?php echo ci_escape($option); ?>" required data-progress-field <?php echo $checked ? 'checked' : ''; ?>>
                                    <span><?php echo ci_escape($option); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="ci-submit"><p>One submission per week. Your answers are private to your account.</p><button class="s-btn primary" type="submit"><span>Review &amp; submit</span><span aria-hidden="true">→</span></button></div>
        </form>
    </section>
<?php else: ?>
    <section class="ci-card ci-availability-card" id="weeklyCheckin" aria-labelledby="availabilityHeading">
        <span class="ci-availability-icon" aria-hidden="true">✦</span>
        <div><div class="s-label">A MOMENT TO REFLECT</div><h2 id="availabilityHeading">Your next check-in opens Sunday</h2><p>Nothing to complete today. Come back on <strong><?php echo ci_escape($nextAvailable->format('l, F j')); ?></strong> to reflect on this week.</p></div>
    </section>
<?php endif; ?>

</div>
</main>

<dialog class="ci-confirm" id="ciConfirmDialog" aria-labelledby="ciConfirmHeading" aria-describedby="ciConfirmText">
    <div class="ci-confirm-icon" aria-hidden="true">&#10003;</div>
    <h2 id="ciConfirmHeading">Ready to submit?</h2>
    <p id="ciConfirmText">You can review your answers now. After submitting, this check-in can’t be edited for this period.</p>
    <div class="ci-confirm-actions"><button type="button" class="s-btn" data-confirm-cancel>Go back</button><button type="button" class="s-btn primary" data-confirm-submit>Submit check-in</button></div>
</dialog>

<script>
(() => {
    const forms = document.querySelectorAll('[data-checkin-form]');
    const dialog = document.getElementById('ciConfirmDialog');
    let activeForm = null;
    forms.forEach(form => {
        const fields = Array.from(form.querySelectorAll('[data-progress-field]'));
        const groups = Array.from(form.querySelectorAll('[data-progress-group]'));
        const progress = form.querySelector('[data-progress]');
        const label = form.querySelector('[data-progress-label]');
        const updateProgress = () => {
            const answered = groups.filter(group => group.querySelector('input[type="radio"]:checked')).length;
            progress.value = answered;
            label.textContent = answered + ' of ' + groups.length + ' answered';
        };
        fields.forEach(field => field.addEventListener('input', updateProgress));
        fields.forEach(field => field.addEventListener('change', updateProgress));
        updateProgress();
        form.addEventListener('submit', event => {
            if (form.dataset.confirmed === 'true') return;
            event.preventDefault();
            if (!form.reportValidity()) return;
            activeForm = form;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else if (window.confirm('Submit this check-in? You can’t edit it afterward.')) {
                form.dataset.confirmed = 'true';
                form.requestSubmit();
            }
        });
    });
    document.querySelector('[data-confirm-cancel]').addEventListener('click', () => dialog.close());
    document.querySelector('[data-confirm-submit]').addEventListener('click', () => {
        if (!activeForm) return;
        const form = activeForm;
        activeForm = null;
        form.dataset.confirmed = 'true';
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        dialog.close();
        form.requestSubmit();
    });
    dialog.addEventListener('close', () => { activeForm = null; });
})();
</script>
</body>
</html>
