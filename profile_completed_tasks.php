<?php
$completedCount = (int)$taskStats['completed'];
$completedPages = max(1, (int)ceil($completedCount / 6));
$completedPage = max(1, min($completedPages, filter_var($_GET['completed_page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$completedOffset = ($completedPage - 1) * 6;
$completedStmt = $conn->prepare("SELECT id,title,subject,deadline,deadline_time,completed_at FROM tasks WHERE user_id=? AND status='Completed' ORDER BY completed_at DESC,id DESC LIMIT 6 OFFSET ?");
$completedStmt->bind_param('ii', $user_id, $completedOffset);
$completedStmt->execute();
$completedEntries = $completedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$completedStmt->close();
?>
<section class="pf-panel pf-completions" id="completed-tasks" aria-labelledby="completedHeading">
<header class="pf-completed-head"><div><div class="s-label">SMALL WINS, REAL PROGRESS</div><h3 id="completedHeading">Your completed tasks <span class="pf-completed-count"><?php echo $completedCount; ?></span></h3><p class="pf-copy">Everything you have finished, newest first. Every step counts.</p></div><a class="s-btn" href="tasks.php">Go to my tasks <span aria-hidden="true">&rarr;</span></a></header>
<?php if (!$completedEntries): ?>
<div class="pf-completed-empty"><span class="pf-finished-icon" aria-hidden="true"><i class="app-icon" data-lucide="circle-check"></i></span><h4>Your next win starts here.</h4><p>Complete a task and it will appear here automatically.</p><a class="s-btn primary" href="tasks.php">Find a task to finish</a></div>
<?php else: ?>
<div class="pf-completed-caption"><span><?php echo $completedOffset + 1; ?>&ndash;<?php echo $completedOffset + count($completedEntries); ?> of <?php echo $completedCount; ?> completed</span><span>Philippine time</span></div>
<ul class="pf-completed-list">
<?php foreach ($completedEntries as $entry): ?>
<li><span class="pf-finished-icon" aria-hidden="true"><i class="app-icon" data-lucide="check"></i></span><div class="pf-finished-content"><a class="pf-finished-title" href="view_task.php?id=<?php echo (int)$entry['id']; ?>&amp;from=history&amp;completed_page=<?php echo $completedPage; ?>"><?php echo profile_text($entry['title']); ?></a><div class="pf-finished-meta"><span><?php echo profile_text(trim($entry['subject'] ?? '') ?: 'General'); ?></span><?php if (!empty($entry['deadline'])): ?><span>Due <?php echo profile_text(app_task_deadline_display($entry['deadline'], $entry['deadline_time'])); ?></span><?php endif; ?></div></div><div class="pf-finished-date"><span class="pf-finished-badge">Completed</span><?php if (!empty($entry['completed_at'])): ?><time datetime="<?php echo profile_text(date('c', strtotime($entry['completed_at']))); ?>"><?php echo date('M j, Y', strtotime($entry['completed_at'])); ?><small><?php echo date('g:i A', strtotime($entry['completed_at'])); ?></small></time><?php else: ?><span class="pf-date-unknown">Completion date unavailable</span><?php endif; ?></div><a class="pf-finished-open" href="view_task.php?id=<?php echo (int)$entry['id']; ?>&amp;from=history&amp;completed_page=<?php echo $completedPage; ?>" aria-label="<?php echo profile_text('View completed task: '.$entry['title']); ?>"><i class="app-icon" data-lucide="arrow-up-right" aria-hidden="true"></i></a></li>
<?php endforeach; ?></ul>
<?php if ($completedPages > 1): ?><nav class="pf-completed-pagination" aria-label="Completed task history pages"><span>Page <?php echo $completedPage; ?> of <?php echo $completedPages; ?></span><?php foreach (['Previous'=>$completedPage-1,'Next'=>$completedPage+1] as $label=>$page): if ($page < 1 || $page > $completedPages) continue; $params = array_intersect_key($_GET, array_flip(['edit','checkin_page'])); $params['completed_page'] = $page; $params['tab'] = 'history'; ?><a class="s-btn" href="profile.php?<?php echo profile_text(http_build_query($params)); ?>#completed-tasks"><?php echo $label; ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php endif; ?></section>
