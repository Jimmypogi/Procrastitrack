<?php
/** Find gaps in recorded activity, including the current unfinished gap. */
function activity_gaps(array $entries, int $now, int $minimumDays): array {
    $events = [];
    foreach ($entries as $entry) {
        $at = strtotime($entry['created_at'] ?? '');
        if ($at !== false && $at <= $now) $events[$at] = $entry;
    }
    ksort($events, SORT_NUMERIC);
    if (!$events) return [];
    $events[$now] = ['created_at' => date('Y-m-d H:i:s', $now), 'log_type' => 'now'];
    $previous = null;
    $gaps = [];
    foreach ($events as $at => $event) {
        if ($previous !== null && $at - $previous['at'] >= $minimumDays * 86400) {
            $gaps[] = ['from' => $previous['entry']['created_at'], 'to' => $event['created_at'], 'days' => round(($at - $previous['at']) / 86400, 1), 'from_type' => $previous['entry']['log_type'] ?? 'study', 'to_type' => $event['log_type'] ?? 'study'];
        }
        $previous = ['at' => $at, 'entry' => $event];
    }
    usort($gaps, fn($a, $b) => $b['days'] <=> $a['days']);
    return $gaps;
}
