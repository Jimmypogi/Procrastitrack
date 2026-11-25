<?php
/** One all-time activity score for Dashboard and Behavior. Does not persist or alter records. */
function app_activity_score(mysqli $conn, int $userId): array {
    $stmt=$conn->prepare("SELECT COUNT(*) total,COALESCE(SUM(status='Completed'),0) completed FROM tasks WHERE user_id=?");
    $stmt->bind_param('i',$userId);$stmt->execute();$tasks=$stmt->get_result()->fetch_assoc();$stmt->close();
    $stmt=$conn->prepare("SELECT COALESCE(SUM(log_type='checkin'),0) checkin_count,COUNT(DISTINCT CASE WHEN log_type='checkin' AND notes LIKE 'Weekly:%' THEN YEARWEEK(created_at,3) END) checkin_weeks,AVG(CASE WHEN log_type='checkin' AND focus_level BETWEEN 1 AND 10 THEN focus_level END) focus FROM procrastiblock_logs WHERE user_id=? AND created_at<=NOW()");
    $stmt->bind_param('i',$userId);$stmt->execute();$logs=$stmt->get_result()->fetch_assoc();$stmt->close();
    $stmt=$conn->prepare('SELECT COUNT(*) sessions,COALESCE(SUM(duration_minutes),0) minutes FROM study_sessions WHERE user_id=? AND duration_minutes BETWEEN 1 AND 600 AND COALESCE(session_date,DATE(created_at))<=CURDATE()');
    $stmt->bind_param('i',$userId);$stmt->execute();$study=$stmt->get_result()->fetch_assoc();$stmt->close();
    $sessions=(int)$study['sessions'];$studyMinutes=(int)$study['minutes'];
    $total=(int)$tasks['total'];$completed=(int)$tasks['completed'];$checkinWeeks=(int)$logs['checkin_weeks'];
    $hasActivity=$total+(int)$logs['checkin_count']+$sessions>0;
    $rate=$total>0?round(100*$completed/$total):0;
    // Transparent app rules for recorded activity, not validated productivity measurements.
    $studyPoints=$studyMinutes>=180?20:($studyMinutes>=120?15:($studyMinutes>=60?10:($studyMinutes>=30?5:0)));
    $parts=[
        'Task completion'=>['points'=>$rate>=80?30:($rate>=50?15:0),'max'=>30,'basis'=>"$completed of $total tasks completed"],
        'Check-in activity'=>['points'=>$checkinWeeks>=4?20:($checkinWeeks>=1?10:0),'max'=>20,'basis'=>$checkinWeeks.' distinct weekly reflection '.($checkinWeeks===1?'week':'weeks').' recorded'],
        'Study activity'=>['points'=>$studyPoints,'max'=>20,'basis'=>"$studyMinutes recorded study minutes across $sessions ".($sessions===1?'session':'sessions').'. Points: 30 min = 5; 60 = 10; 120 = 15; 180+ = 20.'],
        'Reported focus'=>['points'=>$logs['focus']!==null&&(float)$logs['focus']>=7?10:0,'max'=>10,'basis'=>$logs['focus']===null?'No focus rating recorded':round((float)$logs['focus'],1).'/10 average focus'],
    ];
    if(!$hasActivity)foreach($parts as &$part)$part['points']=0;
    unset($part);
    $earned=array_sum(array_column($parts,'points'));
    $possible=array_sum(array_column($parts,'max'));
    $score=$hasActivity?(int)round(min(100,100*$earned/$possible)):null;
    return ['score'=>$score,'earned'=>$earned,'possible'=>$possible,'has_activity'=>$hasActivity,'parts'=>$parts,'label'=>!$hasActivity?'Not enough data yet':($score>=70?'Strong recorded activity':($score>=40?'Steady recorded activity':'Getting started'))];
}
