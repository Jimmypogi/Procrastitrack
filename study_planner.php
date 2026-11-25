<?php
session_start();
if(!isset($_SESSION['user_id'])){header('Location: login.php');exit;}
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/csrf.php';
require_once __DIR__.'/lib/study_planner.php';
study_planner_schema($conn);
$userId=(int)$_SESSION['user_id'];
function planner_text($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');}
function planner_render_saved_plan(string $plan): string {
    $days = [];
    $current = -1;
    foreach (preg_split('/\r?\n/', $plan) as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') continue;
        if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $heading)) {
            $days[] = ['title' => trim($heading[1]), 'items' => []];
            $current = count($days) - 1;
            continue;
        }
        if ($current < 0) {
            $days[] = ['title' => 'Your suggested rhythm', 'items' => []];
            $current = 0;
        }
        $line = preg_replace('/^(?:[-*•]|\d+[.)])\s*/u', '', $line);
        $line = preg_replace('/\*\*(.*?)\*\*|__(.*?)__/', '$1$2', $line);
        if ($line !== '') $days[$current]['items'][] = trim($line);
    }
    if (!$days) return '<p class="planner-empty-plan">No readable plan details were found. Create a fresh plan in Study Coach.</p>';

    $html = '<div class="planner-plan-grid">';
    foreach ($days as $index => $day) {
        $focus = [];
        $break = '';
        $total = '';
        foreach ($day['items'] as $item) {
            if (preg_match('/^Break\s*:\s*(.+)$/i', $item, $match)) $break = trim($match[1]);
            elseif (preg_match('/^Total planned minutes\s*:\s*(.+)$/i', $item, $match)) $total = trim($match[1]);
            else $focus[] = $item;
        }
        if (!$focus) $focus[] = 'Open space';
        $html .= '<article class="planner-plan-day"><header><span class="planner-plan-index">' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '</span><h3>' . planner_text($day['title']) . '</h3></header>';
        foreach ($focus as $itemIndex => $item) {
            $label = preg_match('/optional buffer|rest|open space/i', $item) ? 'OPEN SPACE' : ($itemIndex === 0 ? 'FOCUS BLOCK' : 'NEXT BLOCK');
            $html .= '<div class="planner-plan-focus"><span>' . $label . '</span><p>' . planner_text($item) . '</p></div>';
        }
        if ($break !== '' || $total !== '') {
            $html .= '<div class="planner-plan-footer">';
            if ($break !== '') $html .= '<span>Break <strong>' . planner_text($break) . '</strong></span>';
            if ($total !== '') $html .= '<span>Total <strong>' . planner_text($total) . '</strong></span>';
            $html .= '</div>';
        }
        $html .= '</article>';
    }
    return $html . '</div>';
}
$today=date('Y-m-d');
$values=['title'=>'','block_date'=>$today,'start_time'=>'09:00','end_time'=>'09:30','break_minutes'=>'5','notes'=>'','task_id'=>''];
$error='';$editing=0;
if($_SERVER['REQUEST_METHOD']==='POST'){
    app_csrf_require();
    $action=$_POST['action']??'';
    if($action==='save_plan'){
        $plan=is_string($_POST['plan_text']??null)?trim($_POST['plan_text']):'';
        if($plan===''||mb_strlen($plan)>12000) $error='The suggested plan is empty or too long. Create a new plan in Study Coach.';
        else {
            // Avoid creating a duplicate when the save button is clicked twice.
            $stmt=$conn->prepare('INSERT INTO study_plans(user_id,plan_text) SELECT ?,? WHERE NOT EXISTS(SELECT 1 FROM study_plans WHERE user_id=? AND plan_text=?)');
            $stmt->bind_param('isis',$userId,$plan,$userId,$plan);$stmt->execute();
            $_SESSION['planner_notice']='Suggested plan saved. Add dated study blocks below to put it on your calendar.';
            header('Location: study_planner.php#saved-plan');exit;
        }
    } elseif(in_array($action,['save_block','delete_block'],true)){
        $id=filter_var($_POST['block_id']??0,FILTER_VALIDATE_INT);
        $owned=true;
        if($id===false||$id<0){$owned=false;}
        elseif($id>0){$stmt=$conn->prepare('SELECT id FROM study_blocks WHERE id=? AND user_id=?');$stmt->bind_param('ii',$id,$userId);$stmt->execute();$owned=$stmt->get_result()->num_rows===1;}
        if(!$owned||($action==='delete_block'&&!$id)){$error='That study block is unavailable.';http_response_code(404);}
        elseif($action==='delete_block'){
            $stmt=$conn->prepare('DELETE FROM study_blocks WHERE id=? AND user_id=?');$stmt->bind_param('ii',$id,$userId);$stmt->execute();
            $_SESSION['planner_notice']='Study block removed.';header('Location: study_planner.php#my-blocks');exit;
        } else {
            $editing=(int)$id;[$values,$error]=study_block_values($_POST);
            $taskId=$values['task_id']===''?null:(int)$values['task_id'];
            if($error===''&&$taskId!==null){
                $stmt=$conn->prepare("SELECT id FROM tasks WHERE id=? AND user_id=? AND status<>'Completed'");$stmt->bind_param('ii',$taskId,$userId);$stmt->execute();
                if($stmt->get_result()->num_rows!==1)$error='Choose one of your unfinished tasks, or select General study.';
            }
            if($error===''){
                // Serialize schedule changes per student to prevent simultaneous overlapping saves.
                $conn->begin_transaction();
                try {
                    $stmt=$conn->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$stmt->bind_param('i',$userId);$stmt->execute();$stmt->get_result()->fetch_assoc();
                    $stmt=$conn->prepare('SELECT id FROM study_blocks WHERE user_id=? AND block_date=? AND start_time<? AND end_time>? AND id<>? LIMIT 1');
                    $stmt->bind_param('isssi',$userId,$values['block_date'],$values['end_time'],$values['start_time'],$editing);$stmt->execute();
                    if($stmt->get_result()->num_rows)throw new DomainException('This overlaps another study block. Choose a different time.');
                    if($editing){
                        $stmt=$conn->prepare('UPDATE study_blocks SET task_id=?,title=?,block_date=?,start_time=?,end_time=?,break_minutes=?,notes=? WHERE id=? AND user_id=?');
                        $stmt->bind_param('issssisii',$taskId,$values['title'],$values['block_date'],$values['start_time'],$values['end_time'],$values['break_minutes'],$values['notes'],$editing,$userId);
                    }else{
                        $stmt=$conn->prepare('INSERT INTO study_blocks(task_id,title,block_date,start_time,end_time,break_minutes,notes,user_id) VALUES(?,?,?,?,?,?,?,?)');
                        $stmt->bind_param('issssisi',$taskId,$values['title'],$values['block_date'],$values['start_time'],$values['end_time'],$values['break_minutes'],$values['notes'],$userId);
                    }
                    $stmt->execute();$conn->commit();
                    $_SESSION['planner_notice']=$editing?'Study block updated.':'Study block saved to your calendar.';
                    header('Location: study_planner.php#my-blocks');exit;
                }catch(DomainException $e){$conn->rollback();$error=$e->getMessage();}
                catch(Throwable $e){$conn->rollback();error_log('Study planner: '.$e->getMessage());$error='Could not save your study block. Your entries are still here; please try again.';}
            }
        }
    }else{$error='Choose a valid planner action.';http_response_code(422);}
}elseif(isset($_GET['edit'])){
    $editing=filter_var($_GET['edit'],FILTER_VALIDATE_INT)?:0;
    $stmt=$conn->prepare('SELECT * FROM study_blocks WHERE id=? AND user_id=?');$stmt->bind_param('ii',$editing,$userId);$stmt->execute();$block=$stmt->get_result()->fetch_assoc();
    if(!$block){$error='That study block is unavailable.';$editing=0;http_response_code(404);}
    else{foreach($values as $key=>$_)$values[$key]=(string)($block[$key]??'');$values['start_time']=substr($values['start_time'],0,5);$values['end_time']=substr($values['end_time'],0,5);}
}elseif(isset($_GET['date'])&&is_string($_GET['date'])){
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$_GET['date']);
    if($day&&$day->format('Y-m-d')===$_GET['date']&&$_GET['date']>=$today)$values['block_date']=$_GET['date'];
}
$notice=$_SESSION['planner_notice']??'';unset($_SESSION['planner_notice']);
$stmt=$conn->prepare('SELECT plan_text,created_at FROM study_plans WHERE user_id=? ORDER BY id DESC LIMIT 1');$stmt->bind_param('i',$userId);$stmt->execute();$savedPlan=$stmt->get_result()->fetch_assoc();
$savedPlanIsStale = $savedPlan && date('Y-m-d', strtotime($savedPlan['created_at'])) < $today;
$tasks=$conn->query("SELECT id,title FROM tasks WHERE user_id=$userId AND status<>'Completed' ORDER BY deadline ASC,id DESC")->fetch_all(MYSQLI_ASSOC);
$stmt=$conn->prepare('SELECT b.*,t.title task_title FROM study_blocks b LEFT JOIN tasks t ON t.id=b.task_id AND t.user_id=b.user_id WHERE b.user_id=? AND b.block_date>=? ORDER BY b.block_date,b.start_time LIMIT 60');$stmt->bind_param('is',$userId,$today);$stmt->execute();$blocks=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Study planner | ProcrastiTrack</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/student.css"><link rel="stylesheet" href="assets/css/study-planner.css?v=2"></head><body class="student-body">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="s-main"><div class="s-inner planner-inner"><header class="s-head"><div><div class="s-label">MAKE ROOM FOR PROGRESS</div><h1>Your study planner</h1><p>A little structure for the week. Save a block, make space for a break, and take it one step at a time.</p></div><div class="s-head-actions"><a class="s-btn" href="calendar.php">View calendar</a><a class="s-btn" href="study_coach.php?mode=schedule#coachForm">Plan with Study Coach</a></div></header>
<?php if($notice): ?><p class="planner-notice" role="status"><?php echo planner_text($notice); ?></p><?php endif; ?>
<?php if($error): ?><p class="planner-notice is-error" role="alert"><?php echo planner_text($error); ?></p><?php endif; ?>
<?php if($savedPlan): ?><details class="s-panel planner-suggestion" id="saved-plan"><summary><span><span class="s-label">FROM YOUR STUDY COACH</span><strong>Your saved suggested plan</strong></span><span>View or hide plan &darr;</span></summary><p class="planner-hint">Saved <?php echo date('M j, Y',strtotime($savedPlan['created_at'])); ?>. Use it as a guide and add your preferred dates and times below.</p><?php if($savedPlanIsStale): ?><div class="planner-stale-note"><strong>This plan may be out of date.</strong> Some suggested days have passed. <a href="study_coach.php?mode=schedule#coachForm">Create a fresh plan</a> for the week ahead.</div><?php endif; ?><?php echo planner_render_saved_plan((string)$savedPlan['plan_text']); ?></details><?php endif; ?>
<div class="planner-layout"><section class="s-panel planner-form-panel" id="block-form"><div class="s-label"><?php echo $editing?'MAKE AN ADJUSTMENT':'TIME FOR WHAT MATTERS'; ?></div><h2><?php echo $editing?'Edit study block':'Add a study block'; ?></h2><p class="planner-hint">The time window includes your focus time and break.</p>
<form method="post" action="study_planner.php#block-form" class="s-form-grid"><?php app_csrf_field(); ?><input type="hidden" name="action" value="save_block"><input type="hidden" name="block_id" value="<?php echo $editing; ?>">
<div class="full"><label for="blockTitle">What will you work on?</label><input id="blockTitle" name="title" maxlength="120" placeholder="e.g., Review chapter 3" required value="<?php echo planner_text($values['title']); ?>"></div>
<div class="full"><label for="blockTask">Related task (optional)</label><select id="blockTask" name="task_id"><option value="">General study</option><?php foreach($tasks as $task): ?><option value="<?php echo (int)$task['id']; ?>" <?php echo (int)$values['task_id']===(int)$task['id']?'selected':''; ?>><?php echo planner_text($task['title']); ?></option><?php endforeach; ?></select></div>
<div class="full"><label for="blockDate">Date</label><input id="blockDate" type="date" name="block_date" min="<?php echo $today; ?>" required value="<?php echo planner_text($values['block_date']); ?>"></div>
<div><label for="blockStart">Start time</label><input id="blockStart" type="time" name="start_time" required value="<?php echo planner_text($values['start_time']); ?>"></div><div><label for="blockEnd">End time</label><input id="blockEnd" type="time" name="end_time" required value="<?php echo planner_text($values['end_time']); ?>"></div>
<div class="full"><label for="blockBreak">Break within this block (minutes)</label><input id="blockBreak" type="number" name="break_minutes" min="0" max="719" step="1" required value="<?php echo planner_text($values['break_minutes']); ?>"></div>
<div class="full"><label for="blockNotes">Notes (optional)</label><textarea id="blockNotes" name="notes" maxlength="1000" rows="3" placeholder="Keep your first step small."><?php echo planner_text($values['notes']); ?></textarea></div>
<div class="full planner-form-actions"><?php if($editing): ?><a class="s-btn" href="study_planner.php">Cancel</a><?php endif; ?><button class="s-btn primary" type="submit"><?php echo $editing?'Save changes':'Save study block'; ?></button></div></form></section>
<section class="s-panel" id="my-blocks"><header class="planner-list-head"><div><div class="s-label">YOUR UPCOMING SCHEDULE</div><h2>Space to focus</h2></div><span class="planner-timezone">Philippine time</span></header><p class="planner-hint">Your next <?php echo count($blocks); ?> saved blocks. Scheduled time is a plan; log actual study time in Study Sessions.</p>
<?php if(!$blocks): ?><div class="planner-empty"><i class="app-icon" data-lucide="calendar-plus" aria-hidden="true"></i><h3>A fresh page for your week.</h3><p>Choose one small task and give it a time. Your saved blocks appear here and in Calendar.</p></div><?php endif; ?>
<?php $lastDay='';foreach($blocks as $block): if($lastDay!==$block['block_date']):$lastDay=$block['block_date']; ?><h3 class="planner-day-heading"><?php echo date('l, M j',strtotime($lastDay)); ?></h3><?php endif; $duration=(int)((strtotime($block['end_time'])-strtotime($block['start_time']))/60); ?>
<article class="planner-block"><div class="planner-block-time"><?php echo date('g:i A',strtotime($block['start_time'])); ?><span><?php echo date('g:i A',strtotime($block['end_time'])); ?></span></div><div class="planner-block-body"><h3><?php echo planner_text($block['title']); ?></h3><p><?php echo $duration-(int)$block['break_minutes']; ?> min focus <span>&middot;</span> <?php echo (int)$block['break_minutes']; ?> min break</p><?php if($block['task_title']): ?><a href="view_task.php?id=<?php echo (int)$block['task_id']; ?>"><?php echo planner_text($block['task_title']); ?></a><?php endif; ?><?php if($block['notes']): ?><p class="planner-block-notes"><?php echo nl2br(planner_text($block['notes'])); ?></p><?php endif; ?><div class="planner-block-actions"><a href="study_planner.php?edit=<?php echo (int)$block['id']; ?>#block-form">Edit</a><a href="calendar.php?date=<?php echo $block['block_date']; ?>">Calendar</a><a href="study_sessions.php#pomodoroTimer">Start focus</a><form method="post" action="study_planner.php#my-blocks" class="planner-delete"><?php app_csrf_field(); ?><input type="hidden" name="action" value="delete_block"><input type="hidden" name="block_id" value="<?php echo (int)$block['id']; ?>"><button type="submit" aria-label="<?php echo planner_text('Remove study block: '.$block['title']); ?>">Remove</button></form></div></div></article>
<?php endforeach; ?></section></div></div></main></body></html>
