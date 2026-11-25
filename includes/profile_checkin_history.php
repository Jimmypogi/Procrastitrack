<?php
require_once __DIR__.'/../lib/checkin_support.php';
checkin_support_schema($conn);
$historyPage=filter_var($_GET['checkin_page']??1,FILTER_VALIDATE_INT) ?: 1;
$weeklyEntries = checkin_weekly_history_sql();
$countStmt = $conn->prepare("SELECT COUNT(*) $weeklyEntries");
$countStmt->bind_param('i', $user_id);
$countStmt->execute();
$weeklyCount = (int)$countStmt->get_result()->fetch_row()[0];
$countStmt->close();
$historyPages=max(1,(int)ceil($weeklyCount/6));
$historyPage=max(1,min($historyPages,$historyPage));
$historyOffset=($historyPage-1)*6;
$historyStmt=$conn->prepare("SELECT l.*, r.answers, r.period_start,
    (SELECT title FROM tasks WHERE id=l.task_id AND user_id=l.user_id) AS task_title
    $weeklyEntries ORDER BY l.created_at DESC,l.id DESC LIMIT 6 OFFSET ?");
$historyStmt->bind_param('ii',$user_id,$historyOffset);$historyStmt->execute();
$historyEntries=$historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
$historyNotice = $_SESSION['checkin_history_notice'] ?? '';
unset($_SESSION['checkin_history_notice']);
?>
<section class="pf-panel pf-history" id="checkin-history" aria-labelledby="history-heading">
<header class="pf-history-head"><div><div class="s-label">YOUR REFLECTIONS</div><h3 id="history-heading">Check-in history</h3><p class="pf-copy">Revisit how you felt, what got in the way, and your next steps.</p></div><a class="s-btn" href="checkin.php">New check-in</a></header>
<div class="pf-history-count"><?php echo $weeklyCount; ?> saved weekly <?php echo $weeklyCount === 1 ? 'reflection' : 'reflections'; ?> <span>Philippine time</span></div>
<?php if ($historyNotice !== ''): ?><p class="pf-copy" role="status"><?php echo profile_text($historyNotice); ?></p><?php endif; ?>
<?php if(!$historyEntries): ?><div class="pf-empty">Your story starts here. Save a check-in to see it in your history.</div><?php endif; ?>
<?php foreach($historyEntries as $entry):
$answers=json_decode($entry['answers']??'',true);
$answers=is_array($answers)?$answers:[];
$submitted = new DateTimeImmutable($entry['created_at']);
$weekStart = new DateTimeImmutable($entry['period_start'] ?: checkin_period_start_for_date('Weekly', $submitted->format('Y-m-d')));
?>
<details class="pf-reflection">
<summary><span class="pf-reflection-icon" aria-hidden="true"><i class="app-icon" data-lucide="notebook-pen"></i></span><span class="pf-reflection-heading"><strong><?php echo $weekStart->format('M j'); ?>–<?php echo $weekStart->modify('+6 days')->format('M j, Y'); ?><?php if($entry['mood']): ?> &middot; <?php echo profile_text($entry['mood']); ?><?php endif; ?></strong><time datetime="<?php echo profile_text(date('c',strtotime($entry['created_at']))); ?>"><?php echo date('M j, Y',strtotime($entry['created_at'])); ?> &middot; <?php echo date('g:i A',strtotime($entry['created_at'])); ?></time></span><span class="pf-reflection-chevron" aria-hidden="true">&#8964;</span></summary>
<div class="pf-reflection-body">
<div class="pf-rating-row"><?php foreach(['focus_level'=>'Focus','energy_level'=>'Energy'] as $field=>$label): if($entry[$field]!==null): ?><span><?php echo $label; ?> <b><?php echo (int)$entry[$field]; ?>/10</b></span><?php endif; endforeach; ?></div>
<?php if($entry['task_title']): ?><p class="pf-copy">Related task: <?php echo profile_text($entry['task_title']); ?></p><?php endif; ?>
<dl>
<?php
$fields=['accomplished'=>'What I accomplished','challenges'=>'Challenges','notes'=>'Notes','blocker_detail'=>'What got in the way','next_step'=>'My next step','plan_tomorrow'=>'My plan for tomorrow','weekly_productivity'=>'Weekly productivity (out of 10)','weekly_went_well'=>'What went well','weekly_didnt_work'=>'What did not work','weekly_goals'=>'Goals for next week','weekly_reflections'=>'Reflection notes'];
if(!empty($answers['blocker'])) {
 $blockers=checkin_blockers();
 echo '<dt>Selected obstacle</dt><dd>'.profile_text($blockers[$answers['blocker']]??$answers['blocker']).'</dd>';
}
$shown=false;
foreach (($answers['responses'] ?? []) as $response) {
    if (!is_array($response) || !is_scalar($response['answer'] ?? null) || trim((string)$response['answer']) === '') continue;
    $shown = true;
    echo '<dt>'.profile_text($response['question'] ?? 'Answer').'</dt><dd>'.profile_text($response['answer']).'</dd>';
}
foreach($fields as $field=>$label) {
 $value=$answers[$field]??'';
 if(is_scalar($value)&&trim((string)$value)!=='') { $shown=true; echo '<dt>'.profile_text($label).'</dt><dd>'.profile_text($value).'</dd>'; }
}
if(!$shown) {
 $reason=trim($entry['reason']??'');
 $note=trim(preg_replace('/^(Daily|Weekly):\s*/','',$entry['notes']??''));
 foreach(['Reflection'=>$reason,'Notes'=>$note] as $label=>$value) if($value!=='') echo '<dt>'.$label.'</dt><dd>'.profile_text($value).'</dd>';
}
?>
</dl>
<details class="pf-reflection-delete">
    <summary>Delete reflection</summary>
    <form method="post" action="checkin_history.php">
        <p>Delete this reflection and its saved answers? This can’t be undone.</p>
        <?php app_csrf_field(); ?>
        <input type="hidden" name="entry_id" value="<?php echo (int)$entry['id']; ?>">
        <input type="hidden" name="source" value="profile">
        <input type="hidden" name="page" value="<?php echo $historyPage; ?>">
        <button type="submit">Permanently delete</button>
    </form>
</details>
</div></details>
<?php endforeach; ?>
<?php if($historyPages>1): ?><nav class="pf-history-pages" aria-label="Check-in history pages">
<?php if($historyPage>1): ?><a class="s-btn" href="?checkin_page=<?php echo $historyPage-1; ?>&amp;tab=history#checkin-history">Newer</a><?php endif; ?>
<span>Page <?php echo $historyPage; ?> of <?php echo $historyPages; ?></span>
<?php if($historyPage<$historyPages): ?><a class="s-btn" href="?checkin_page=<?php echo $historyPage+1; ?>&amp;tab=history#checkin-history">Older</a><?php endif; ?>
</nav><?php endif; ?>
</section>
<style>
.pf-history{margin-top:24px;scroll-margin-top:90px}.pf-history-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}.pf-history-head .s-btn{white-space:nowrap;font-size:.8rem}.pf-history-count{display:flex;justify-content:space-between;padding:12px 0 18px;color:var(--profile-muted);font-size:.75rem;border-bottom:1px solid var(--profile-line)}.pf-reflection{border-bottom:1px solid var(--profile-line)}.pf-reflection summary{display:flex;align-items:center;gap:14px;padding:20px 4px;cursor:pointer;list-style:none;color:var(--profile-ink)}.pf-reflection summary::-webkit-details-marker{display:none}.pf-reflection summary:hover{background:var(--profile-soft)}.pf-reflection summary:focus-visible{outline:3px solid #91ad99;outline-offset:2px;border-radius:10px}.pf-reflection-icon{width:40px;height:40px;display:grid;place-items:center;background:var(--profile-soft);color:var(--profile-green);border-radius:12px;flex-shrink:0}.pf-reflection-icon .app-icon{width:18px;height:18px}.pf-reflection-heading{flex:1}.pf-reflection-heading strong{font-size:.85rem}.pf-reflection time{display:block;font-size:.73rem;color:var(--profile-muted);margin-top:6px}.pf-reflection-chevron{color:var(--profile-muted)}.pf-reflection[open] .pf-reflection-chevron{transform:rotate(180deg)}.pf-reflection-body{padding:0 18px 22px 58px}.pf-reflection-body dl{margin:0}.pf-reflection-body dt{font-size:.74rem;color:var(--profile-muted);margin:16px 0 5px}.pf-reflection-body dd{margin:0;color:var(--profile-ink);font-size:.85rem;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}.pf-rating-row{display:flex;flex-wrap:wrap;gap:10px}.pf-rating-row span{background:var(--profile-soft);padding:8px 12px;border-radius:9px;color:var(--profile-muted);font-size:.76rem}.pf-rating-row b{color:var(--profile-ink);margin-left:8px}.pf-history-pages{display:flex;align-items:center;justify-content:center;gap:20px;margin-top:22px;font-size:.8rem;color:var(--profile-muted)}@media(max-width:550px){.pf-history-head{flex-direction:column;gap:0}.pf-reflection-body{padding-left:4px}.pf-history-count{margin-top:18px}}
.pf-history .pf-reflection{margin-top:12px;border:1px solid var(--profile-line);border-radius:14px;overflow:hidden}
.pf-history .pf-reflection>summary{background:var(--profile-soft);padding:14px 16px}
.pf-history .pf-reflection-body{padding:6px 16px 16px}
.pf-history .pf-reflection-body dd{padding:8px 12px;background:var(--profile-soft);border-radius:8px}
.pf-reflection-delete{--delete-ink:#a52732;margin-top:16px;padding-top:10px;border-top:1px solid var(--profile-line)}
.dark-mode .pf-reflection-delete{--delete-ink:#ffadb5}
.pf-reflection .pf-reflection-delete>summary{display:list-item;width:fit-content;padding:4px 0;color:var(--delete-ink);font-size:.8rem;font-weight:700}
.pf-reflection-delete form{margin-top:8px;padding:12px;background:var(--profile-soft);border-radius:10px;color:var(--profile-ink);font-size:.82rem}
.pf-reflection-delete button{border:1px solid currentColor;border-radius:8px;padding:7px 11px;background:transparent;color:var(--delete-ink);font:inherit;font-weight:700;cursor:pointer}
</style>
