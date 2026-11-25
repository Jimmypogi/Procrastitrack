<?php
require_once __DIR__.'/auth.php';
admin_require_role(['superadmin','admin']);
require_once __DIR__.'/../config/database.php';
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
if(!$id || $id<1){http_response_code(400);exit('Choose a valid student.');}
$stmt=$conn->prepare('SELECT id,fullname,email,is_active,registration_date FROM users WHERE id=?');
$stmt->bind_param('i',$id);$stmt->execute();$student=$stmt->get_result()->fetch_assoc();
if(!$student){http_response_code(404);exit('Student not found.');}
function student_metric($conn,$id,$sql){$s=$conn->prepare($sql);$s->bind_param('i',$id);$s->execute();return $s->get_result()->fetch_row()[0];}
$metrics=[];
foreach([
'Total tasks'=>"SELECT COUNT(*) FROM tasks WHERE user_id=?",
'Completed tasks'=>"SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='Completed'",
'Pending tasks'=>"SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='Pending'",
'In progress'=>"SELECT COUNT(*) FROM tasks WHERE user_id=? AND status='In Progress'",
'Overdue tasks'=>"SELECT COUNT(*) FROM tasks WHERE user_id=? AND status<>'Completed' AND " . app_task_overdue_sql(),
'Study sessions'=>"SELECT COUNT(*) FROM study_sessions WHERE user_id=?",
'Study minutes'=>"SELECT COALESCE(SUM(duration_minutes),0) FROM study_sessions WHERE user_id=?",
'Check-ins'=>"SELECT COUNT(*) FROM procrastiblock_logs WHERE user_id=? AND log_type='checkin'",
'Delay logs'=>"SELECT COUNT(*) FROM procrastiblock_logs WHERE user_id=? AND log_type='delay'",
'Reported delay minutes'=>"SELECT COALESCE(SUM(duration_minutes),0) FROM procrastiblock_logs WHERE user_id=? AND log_type='delay'",
'Average focus (out of 10)'=>"SELECT ROUND(AVG(focus_level),1) FROM procrastiblock_logs WHERE user_id=? AND log_type='checkin'",
'Average energy (out of 10)'=>"SELECT ROUND(AVG(energy_level),1) FROM procrastiblock_logs WHERE user_id=? AND log_type='checkin'"
] as $label=>$sql) $metrics[$label]=student_metric($conn,$id,$sql)??'No ratings';
$rate=$metrics['Total tasks']?round($metrics['Completed tasks']/$metrics['Total tasks']*100):0;
$metrics['Completion rate (%)']=$rate;
if(($_GET['download']??'')==='csv'){
 header('Content-Type: text/csv; charset=utf-8');
 header('Cache-Control: no-store');
 header('Content-Disposition: attachment; filename="student-'.$id.'-summary-'.date('Y-m-d').'.csv"');
 $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
 function summary_csv($out,$row){$safe=array_map(function($v){$v=(string)$v;return preg_match('/^[\s]*[=+@-]/u',$v)||preg_match('/^[\t\r\n]/',$v)?"'".$v:$v;},$row);fputcsv($out,$safe,',','"','');}
 foreach([['ProcrastiTrack student summary'],['Generated',date('Y-m-d H:i:s').' Asia/Manila'],['Period','All recorded history; overdue status as of export'],['Student ID',$id],['Name',$student['fullname']],['Email',$student['email']],['Account',$student['is_active']?'Active':'Paused'],[],['Metric','Value']] as $row)summary_csv($out,$row);
 foreach($metrics as $label=>$value)summary_csv($out,[$label,$value]);
 fclose($out);exit;
}
require_once __DIR__.'/_layout.php';
admin_page_start('Student summary','users');
?>
<section class="card student-summary-hero"><div><p class="eyebrow">STUDENT REPORT</p><h2><?php echo htmlspecialchars($student['fullname']); ?></h2><p class="muted"><?php echo htmlspecialchars($student['email']); ?></p><span class="badge <?php echo $student['is_active']?'good':'risk'; ?>"><?php echo $student['is_active']?'Active account':'Paused account'; ?></span></div><div class="toolbar"><a class="button secondary" href="users.php">Back to students</a><a class="button primary" href="?id=<?php echo $id; ?>&amp;download=csv">Export student CSV</a></div></section>
<p class="muted">All recorded history &middot; Updated <?php echo date('M j, Y, g:i A'); ?> Philippine time. Missing ratings are shown as no ratings.</p>
<section class="grid stats"><?php foreach(['Total tasks','Completed tasks','Study minutes','Check-ins'] as $label): ?><article class="card"><div class="stat-label"><?php echo $label; ?></div><div class="stat-value"><?php echo $metrics[$label]; ?></div><div class="stat-meta">All time</div></article><?php endforeach; ?></section>
<section class="grid two"><article class="card"><div class="card-title"><h2>Task progress</h2><span class="badge good"><?php echo $rate; ?>% completed</span></div><?php foreach(['Pending tasks','In progress','Overdue tasks'] as $label): ?><div class="metric-pair"><span><?php echo $label; ?></span><strong><?php echo $metrics[$label]; ?></strong></div><?php endforeach; ?><p class="muted">Overdue means an unfinished task with a deadline before today.</p></article><article class="card"><div class="card-title"><h2>Study &amp; reflection</h2></div><?php foreach(['Study sessions','Delay logs','Reported delay minutes','Average focus (out of 10)','Average energy (out of 10)'] as $label): ?><div class="metric-pair"><span><?php echo $label; ?></span><strong><?php echo $metrics[$label]; ?></strong></div><?php endforeach; ?></article></section>
<p class="privacy-note">This report contains student identity and activity totals. Personal reflection notes and delay reasons are not included.</p>
<?php admin_page_end(); ?>
