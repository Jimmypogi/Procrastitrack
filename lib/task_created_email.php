<?php
/** Returns a delivery state, never makes task creation depend on SMTP. */
function app_send_created_task_email(mysqli $conn, int $uid, int $taskId, string $reminder = '', ?string $expectedDeadline = null, ?string $expectedDeadlineTime = null): string {
 try {
    $stmt=$conn->prepare('SELECT t.title,t.deadline,t.deadline_time,t.priority,t.status,u.is_active,u.reminder_notifications,u.fullname,u.email,u.email_notifications FROM tasks t JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.user_id=?');
    $stmt->bind_param('ii',$taskId,$uid);$stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$row) return 'failed';
    if(empty($row['is_active']) || empty($row['email_notifications'])) return 'disabled';
    if($reminder!=='') {
        $expected=date('Y-m-d',strtotime($reminder==='tomorrow'?'+1 day':'today'));
        if(!in_array($reminder,['today','tomorrow'],true) || empty($row['reminder_notifications']) || $row['status']==='Completed' || $row['deadline']!==$expected || $row['deadline']!==$expectedDeadline || $row['deadline_time']!==$expectedDeadlineTime || ($reminder==='today' && app_task_is_overdue($row['deadline'],$row['deadline_time']))) return 'skipped';
    }
    if(!filter_var($row['email'],FILTER_VALIDATE_EMAIL)) return 'failed';
    $path=__DIR__.'/../config/mail.local.php';
    if(!is_file($path)) return 'unconfigured';
    $config=require $path;
    if(!is_array($config)||empty($config['gmail_address'])||empty($config['app_password'])) return 'unconfigured';
    require_once __DIR__.'/task_email_access.php';
    require_once __DIR__.'/../vendor/autoload.php';
    $mail=new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();$mail->Host='smtp.gmail.com';$mail->SMTPAuth=true;
    $mail->Username=$config['gmail_address'];
    $mail->Password=preg_replace('/\s+/','',$config['app_password']);
    $mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port=587;$mail->Timeout=10;$mail->CharSet='UTF-8';
    $mail->setFrom($config['gmail_address'],$config['from_name']??'ProcrastiTrack');
    $mail->addAddress($row['email'],$row['fullname']);
    $mail->isHTML(true);
    $escape=fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $url=app_create_task_email_access_link($conn,$uid,$taskId,(string)($config['app_url']??''));
    $link=is_string($url)
       ? '<p><a href="'.$escape($url).'" style="display:inline-block;padding:12px 18px;background:#668872;border-radius:10px;color:#fff;text-decoration:none">View your task</a></p>' : '';
    $mail->Subject=($reminder!==''?'Task due '.$reminder.': ':'Task created: ').preg_replace('/[\r\n]+/',' ',$row['title']);
    $dueText=app_task_deadline_display($row['deadline'],$row['deadline_time'],'F j, Y');
    $mail->Body='<div style="background:#f3f6f2;padding:30px 16px;font-family:Arial,sans-serif;color:#2d4033"><div style="max-width:560px;margin:auto;background:white;border:1px solid #dfe8e1;border-radius:18px;padding:28px"><p style="color:#6d8f7a;font-size:12px;letter-spacing:2px">PROCRASTITRACK</p><h1 style="font-size:24px">Your task is ready</h1><p>Hi '.$escape($row['fullname']).', your new task has been saved.</p><div style="background:#f4f8f4;border-radius:12px;padding:18px"><h2 style="font-size:18px;margin-top:0">'.$escape($row['title']).'</h2><p>Due: '.(empty($row['deadline'])?'No deadline':$escape($dueText)).'</p><p>AI-assigned priority: '.$escape($row['priority']).'</p></div>'.$link.'<p style="font-size:12px;color:#7b897f">You receive these updates because Email Notifications is enabled in Settings.</p></div></div>';
    $mail->AltBody="Your task has been saved.\nTitle: ".$row['title']."\nDue: ".($row['deadline'] ? $dueText : 'No deadline')."\nAI-assigned priority: ".$row['priority'].($url ? "\nView: ".$url : '');
    if($reminder!=='') {
        $mail->Body=str_replace(['Your task is ready','your new task has been saved.','Email Notifications is enabled'],['Your task is due '.$reminder,'your task is due '.$reminder.'. Take a moment to review your next step.','Email and Reminder Notifications are enabled'],$mail->Body);
        $mail->AltBody=str_replace('Your task has been saved.','Reminder: your task is due '.$reminder.'.',$mail->AltBody);
    }
    $mail->send();
    return 'sent';
 } catch(Throwable $e) {
    error_log('Task-created email failed: '.$e->getMessage());
    return 'failed';
 }
}
