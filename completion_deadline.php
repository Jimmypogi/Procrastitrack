<?php
/** Resolve a completion deadline from evidence, never from today's edited deadline alone. */
function task_completion_deadline(array $task, array $history): array {
    $unknown=['known'=>false,'deadline'=>null];
    if(empty($task['completed_at']))return $unknown;
    if(!empty($task['completion_deadline_known']))return ['known'=>true,'deadline'=>$task['completion_deadline']??null];
    $events=array_values(array_filter($history,fn($event)=>(int)$event['task_id']===(int)$task['id']&&(int)$event['user_id']===(int)$task['user_id']&&!str_contains(strtolower($event['change_reason']??''),'migration')));
    usort($events,fn($a,$b)=>strcmp($a['changed_at'],$b['changed_at'])?:((int)$a['id']<=>(int)$b['id']));
    $before=null;$after=[];
    foreach($events as $event){
        // Second-resolution legacy timestamps cannot establish ordering for a same-second edit.
        $order=strcmp($event['changed_at'],$task['completed_at']);
        if($order===0)return $unknown;
        if($order<0)$before=$event;else $after[]=$event;
    }
    if(!$before&&!$after)return $unknown;
    $deadline=$before?$before['new_deadline']:$after[0]['old_deadline'];
    $current=$deadline;
    foreach($after as $event){
        if($event['old_deadline']!==$current)return $unknown;
        $current=$event['new_deadline'];
    }
    // A broken history chain may hide unrecorded edits, including removed deadlines.
    if($current!==($task['deadline']??null))return $unknown;
    return ['known'=>true,'deadline'=>$deadline];
}
