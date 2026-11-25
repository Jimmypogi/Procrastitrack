<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/checkin_support.php';
require_once __DIR__ . '/csrf.php';

$userId = (int)$_SESSION['user_id'];
checkin_support_schema($conn);
function checkin_history_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$weeklyEntries = checkin_weekly_history_sql();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_csrf_require();
    $entryId = filter_var($_POST['entry_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $returnPage = max(1, (int)($_POST['page'] ?? 1));
    $deleted = false;
    if ($entryId !== false) {
        try {
            $conn->begin_transaction();
            $entryStmt = $conn->prepare("SELECT l.id $weeklyEntries AND l.id=? FOR UPDATE");
            $entryStmt->bind_param('ii', $userId, $entryId);
            $entryStmt->execute();
            $belongs = $entryStmt->get_result()->num_rows === 1;
            $entryStmt->close();
            if ($belongs) {
                $reflection = $conn->prepare('DELETE FROM checkin_reflections WHERE log_id=? AND user_id=?');
                $reflection->bind_param('ii', $entryId, $userId);
                $reflection->execute();
                $reflection->close();
                $log = $conn->prepare("DELETE FROM procrastiblock_logs WHERE id=? AND user_id=? AND log_type='checkin'");
                $log->bind_param('ii', $entryId, $userId);
                $log->execute();
                $log->close();
                $cache = $conn->prepare('DELETE FROM user_tendency WHERE user_id=?');
                $cache->bind_param('i', $userId);
                $cache->execute();
                $cache->close();
            }
            $conn->commit();
            $deleted = $belongs;
        } catch (mysqli_sql_exception $error) {
            $conn->rollback();
            error_log('Weekly check-in deletion failed: ' . $error->getMessage());
        }
    }
    $_SESSION['checkin_history_notice'] = $deleted ? 'Weekly reflection deleted.' : 'This reflection could not be deleted.';
    $returnUrl = ($_POST['source'] ?? '') === 'profile'
        ? 'profile.php?tab=history&checkin_page=' . $returnPage . '#checkin-history'
        : 'checkin_history.php?page=' . $returnPage;
    header('Location: ' . $returnUrl, true, 303);
    exit();
}

$notice = $_SESSION['checkin_history_notice'] ?? '';
unset($_SESSION['checkin_history_notice']);
$countStmt = $conn->prepare("SELECT COUNT(*) $weeklyEntries");
$countStmt->bind_param('i', $userId);
$countStmt->execute();
$totalEntries = (int)$countStmt->get_result()->fetch_row()[0];
$countStmt->close();

$pageSize = 10;
$totalPages = max(1, (int)ceil($totalEntries / $pageSize));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $pageSize;
$entriesStmt = $conn->prepare("SELECT l.id, l.created_at, l.notes, l.reason, r.answers, r.recommendation, r.period_start
    $weeklyEntries
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT ? OFFSET ?");
$entriesStmt->bind_param('iii', $userId, $pageSize, $offset);
$entriesStmt->execute();
$history = $entriesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$entriesStmt->close();
$timezone = new DateTimeZone('Asia/Manila');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check-in History | ProcrastiTrack</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/checkin.css?v=weekly-history-v3">
</head>
<body class="student-body checkin-page checkin-history-page">
<?php include 'includes/sidebar.php'; ?>
<main class="s-main">
<div class="s-inner">
    <header class="s-head ci-page-header">
        <div><div class="s-label">LOOKING BACK</div><h1>Check-in history</h1><p>Your past reflections, answers, and recommendations are saved here for you to review.</p></div>
        <a class="s-btn" href="checkin.php#weeklyCheckin"><span aria-hidden="true">←</span> Back to weekly check-in</a>
    </header>

    <?php if ($notice !== ''): ?><p class="ci-note" role="status"><?php echo checkin_history_escape($notice); ?></p><?php endif; ?>
    <section class="ci-card ci-history" aria-labelledby="ciHistoryHeading">
        <div class="ci-card-heading">
            <h2 id="ciHistoryHeading">Weekly reflections</h2>
            <span class="ci-history-count"><?php echo $totalEntries; ?> saved</span>
        </div>
        <?php if ($history): ?>
            <div class="ci-weekly-history-list">
            <?php foreach ($history as $entry):
                $answers = json_decode((string)($entry['answers'] ?? ''), true);
                $answers = is_array($answers) ? $answers : [];
                $date = new DateTimeImmutable($entry['created_at'], $timezone);
                $periodStart = $entry['period_start'] ?: ($answers['period_start'] ?? checkin_period_start_for_date('Weekly', $date->format('Y-m-d')));
                $periodDate = new DateTimeImmutable($periodStart, $timezone);
                $periodLabel = $periodDate->format('M j') . '–' . $periodDate->modify('+6 days')->format('M j, Y');
            ?>
                <details class="ci-history-entry">
                    <summary>
                        <span><strong><?php echo checkin_history_escape($periodLabel); ?></strong><time datetime="<?php echo checkin_history_escape($date->format(DateTimeInterface::ATOM)); ?>">Submitted <?php echo checkin_history_escape($date->format('M j, Y · g:i A')); ?></time></span>
                        <span class="ci-history-arrow" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="ci-history-body">
                        <div class="ci-history-answers">
                        <?php if (isset($answers['responses']) && is_array($answers['responses'])): ?>
                            <?php foreach ($answers['responses'] as $response): if (!is_array($response) || trim((string)($response['answer'] ?? '')) === '') continue; ?>
                                <p><strong><?php echo checkin_history_escape($response['question'] ?? 'Answer'); ?></strong><br><?php echo nl2br(checkin_history_escape($response['answer'])); ?></p>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php
                            $legacyLabels = [
                                'focus_score' => 'Focus rating', 'energy_score' => 'Energy rating', 'mood' => 'Mood',
                                'blocker' => 'What got in the way', 'blocker_detail' => 'In your words', 'accomplished' => 'What you managed to do',
                                'next_step' => 'Your next step', 'weekly_productivity' => 'Weekly productivity', 'weekly_went_well' => 'What helped',
                                'weekly_didnt_work' => 'What made studying difficult', 'weekly_goals' => 'Next week',
                            ];
                            foreach (checkin_questions()['Weekly'] as $field => $question) {
                                $legacyLabels[$field] = $question['question'];
                            }
                            foreach ($legacyLabels as $field => $label):
                                if (!isset($answers[$field]) || trim((string)$answers[$field]) === '') continue;
                            ?>
                                <p><strong><?php echo checkin_history_escape($label); ?></strong><br><?php echo nl2br(checkin_history_escape($answers[$field])); ?></p>
                            <?php endforeach; ?>
                            <?php if (!$answers && !empty($entry['reason'])): ?><p><?php echo nl2br(checkin_history_escape($entry['reason'])); ?></p><?php endif; ?>
                        <?php endif; ?>
                        </div>
                        <?php if (is_array($answers['analysis'] ?? null)):
                            $checkinAnalysis = $answers['analysis'];
                            $checkinAnalysisId = (int)$entry['id'];
                            include __DIR__ . '/includes/checkin_analysis_card.php';
                            unset($checkinAnalysis, $checkinAnalysisId);
                        elseif (!empty($entry['recommendation'])): ?>
                            <p class="ci-history-recommendation"><strong>Recommendation</strong><br><?php echo checkin_history_escape($entry['recommendation']); ?></p>
                        <?php endif; ?>
                        <details class="ci-history-delete">
                            <summary>Delete reflection</summary>
                            <form method="post" action="checkin_history.php">
                                <p>Delete this reflection and its saved answers? This can’t be undone.</p>
                                <?php app_csrf_field(); ?>
                                <input type="hidden" name="entry_id" value="<?php echo (int)$entry['id']; ?>">
                                <input type="hidden" name="page" value="<?php echo $page; ?>">
                                <button type="submit">Permanently delete</button>
                            </form>
                        </details>
                    </div>
                </details>
            <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="ci-history-pagination" aria-label="Check-in history pages">
                    <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <?php if ($page > 1): ?><a class="s-btn" href="checkin_history.php?page=<?php echo $page - 1; ?>">← Previous</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a class="s-btn" href="checkin_history.php?page=<?php echo $page + 1; ?>">Next →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="ci-history-empty"><span aria-hidden="true">✦</span><p>No check-ins yet. Your submitted weekly reflections will appear here.</p></div>
        <?php endif; ?>
    </section>
</div>
</main>
</body>
</html>
