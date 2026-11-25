<?php
// A visit streak measures signed-in page visits, not study performance.
function app_record_visit(mysqli $conn, int $userId): void {
    $conn->query("CREATE TABLE IF NOT EXISTS user_activity_days (
        user_id INT NOT NULL, activity_date DATE NOT NULL,
        first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id, activity_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $day=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $stmt=$conn->prepare('INSERT INTO user_activity_days (user_id,activity_date) VALUES (?,?) ON DUPLICATE KEY UPDATE activity_date=VALUES(activity_date)');
    $stmt->bind_param('is',$userId,$day); $stmt->execute(); $stmt->close();
}
function app_streak_summary(array $dates, string $today): array {
    $dates=array_values(array_unique(array_filter($dates,fn($day)=>$day<=$today)));
    sort($dates);
    $best=0; $run=0; $previous=null;
    foreach($dates as $day) {
        $run=$previous!==null && (new DateTimeImmutable($previous))->modify('+1 day')->format('Y-m-d')===$day ? $run+1 : 1;
        $best=max($best,$run); $previous=$day;
    }
    $active=array_fill_keys($dates,true);
    $cursor=new DateTimeImmutable($today);
    if(!isset($active[$today])) $cursor=$cursor->modify('-1 day');
    $current=0;
    while(isset($active[$cursor->format('Y-m-d')])) { $current++; $cursor=$cursor->modify('-1 day'); }
    return ['current'=>$current,'best'=>$best,'total'=>count($dates),'active'=>$active];
}
