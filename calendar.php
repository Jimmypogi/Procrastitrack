<?php
session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
$user_id = intval($_SESSION['user_id']);
$today_date = date('Y-m-d');
$selected_date = $today_date;
$requested = $_GET['date'] ?? '';
if (is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $requested);
    if ($parsed && $parsed->format('Y-m-d') === $requested && $requested >= '1000-01-01' && $requested <= '9999-12-31') {
        $selected_date = $requested;
    }
}
$selected = new DateTimeImmutable($selected_date);
$month_start = $selected->modify('first day of this month');
$month_end = $month_start->modify('+1 month');
$current_month = (int)$selected->format('n');
$current_year = (int)$selected->format('Y');
$days_in_month = (int)$selected->format('t');
$first_day = (int)$month_start->format('N');
$previous_month = $month_start->modify('-1 month')->format('Y-m-d');
$next_month = $month_end->format('Y-m-d');
$start = $month_start->format('Y-m-d');
$end = $month_end->format('Y-m-d');
require_once __DIR__.'/lib/study_planner.php';
study_planner_schema($conn);
$plannerStmt=$conn->prepare('SELECT id,title,block_date,start_time,end_time,break_minutes FROM study_blocks WHERE user_id=? AND block_date>=? AND block_date<? ORDER BY block_date,start_time');
$plannerStmt->bind_param('iss',$user_id,$start,$end);$plannerStmt->execute();
$plannerByDate=[];
foreach($plannerStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $block) $plannerByDate[$block['block_date']][]=$block;
$event_stmt = $conn->prepare('SELECT id, title, deadline, deadline_time, completed_at, priority, status FROM tasks WHERE user_id=? AND ((deadline >= ? AND deadline < ?) OR (completed_at >= ? AND completed_at < ?)) ORDER BY deadline ASC, deadline_time ASC, id ASC');
$event_stmt->bind_param('issss', $user_id, $start, $end, $start, $end);
$event_stmt->execute();
$month_events = $event_stmt->get_result();

$all_deadlines = mysqli_query($conn, "SELECT * FROM tasks WHERE user_id=$user_id AND status!='Completed' AND " . app_task_deadline_upcoming_sql() . " ORDER BY deadline ASC, deadline_time ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar — ProcrastiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard_modern.css">
<style>
.calendar-card { max-width: 700px; margin-inline: auto; }
.calendar-card > .panel-header { display: block; margin-bottom: 14px; }
.calendar-card .panel-title { font-size: 1.15rem; }
.calendar-caption { margin: 4px 0 0; color: #82908a; font-size: .82rem; }
.calendar { padding: 12px; margin-bottom: 0; border: 1px solid #edf1ee; background: #fafcfb; }
.calendar-weekdays { gap: 6px; margin-bottom: 6px; }
.calendar-days { grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.calendar-day { aspect-ratio: auto; min-height: 48px; border-radius: 10px; }
.calendar-day[data-date] { font-size: .86rem; }
.calendar-day .day-number { font-size: .84rem; }
.calendar-day .day-event-indicator { bottom: 5px; }
@media (max-width: 640px) { .calendar-card { max-width: 100%; } .calendar { padding: 8px; } .calendar-weekdays, .calendar-days { gap: 3px; } .calendar-day { min-height: 42px; border-radius: 8px; } } .calendar-day[data-date] { color: inherit; text-decoration: none; border: 0; font: inherit; cursor: pointer; }
.calendar-day[data-date]:focus-visible { outline: 3px solid #9ab7a6; outline-offset: 2px; }
.calendar-day.selected:not(.today) { background: #e8f0ec; box-shadow: inset 0 0 0 2px #6b8f7a; }
.calendar-day.today.selected { box-shadow: inset 0 0 0 3px #d7e7dc; }
.calendar-task-group-title { margin: 18px 0 10px; color: #6b8f7a; font-size: .76rem; font-weight: 800; text-transform: uppercase; letter-spacing: .09em; }
#selected-date-summary { margin: 5px 0 0; color: #82908a; font-size: .85rem; }
.selected-day-tasks { padding: 24px; }
.selected-day-tasks .panel-header { display: block; margin-bottom: 18px; }
.selected-day-tasks .panel-title { font-size: 1.15rem; }
.selected-day-tasks .subject-breakdown { gap: 10px; }
.selected-day-tasks .calendar-task-item { display: grid; grid-template-columns: 92px minmax(0, 1fr) auto; align-items: center; gap: 14px; min-height: 70px; padding: 14px 16px; border: 1px solid #e8eeea; border-radius: 14px; background: #fbfcfb; color: inherit; text-decoration: none; transition: border-color .18s ease, background .18s ease, transform .18s ease; }
.selected-day-tasks .calendar-task-item:hover { border-color: #b8cfc0; background: #f5f9f6; transform: translateY(-1px); }
.calendar-task-info { display: flex; min-width: 0; flex-direction: column; gap: 5px; }
.calendar-task-info .subject-name { font-size: .94rem; line-height: 1.35; }
.calendar-task-info .subject-count { font-size: .78rem; }
.calendar-task-priority, .calendar-task-status { display: inline-flex; align-items: center; justify-content: center; width: fit-content; padding: 6px 10px; border-radius: 999px; background: #eef2f0; color: #64746b; font-size: .72rem; font-weight: 700; white-space: nowrap; }
.calendar-task-priority.priority-high { background: #fff0ee; color: #c64e43; }
.calendar-task-priority.priority-medium { background: #fff6e4; color: #9a6a13; }
.calendar-task-priority.priority-low { background: #edf7f0; color: #4f8062; }
.calendar-task-status.status-completed { background: #eaf5ee; color: #43805a; }
.calendar-task-status.status-pending { background: #fff6e4; color: #9a6a13; }
.selected-day-tasks .empty-state { padding: 30px 18px; border: 1px dashed #d8e2db; border-radius: 14px; background: #fafcfb; color: #809087; text-align: center; }
@media (max-width: 640px) { .selected-day-tasks { padding: 18px; } .selected-day-tasks .calendar-task-item { grid-template-columns: 1fr auto; gap: 8px 12px; padding: 12px; } .calendar-task-priority { grid-column: 1 / -1; } }

.calendar-workspace{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:24px;align-items:start;margin-bottom:24px}
.calendar-workspace .calendar-card{max-width:none;width:100%;margin:0;box-sizing:border-box;padding:24px}
.calendar-workspace .selected-day-tasks{margin:0;min-width:0}
.calendar-month-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}
.calendar-eyebrow{display:block;color:#6d8f7a;font-size:.68rem;letter-spacing:.12em;font-weight:800;margin-bottom:7px}
.calendar-navigation{display:flex;align-items:center;gap:6px}
.calendar-navigation a,.calendar-jump button{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;border:1px solid #dfe8e2;border-radius:10px;background:#fff;color:#496b56;text-decoration:none;font-size:1.4rem;cursor:pointer}
.calendar-navigation .calendar-today{font-size:.78rem;padding:0 12px;font-weight:700}
.calendar-navigation a:hover,.calendar-jump button:hover{background:#edf4ef}
.calendar-jump{display:flex;align-items:center;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e7ede8;flex-wrap:wrap}
.calendar-jump label{font-size:.78rem;color:#738279;margin-right:auto}
.calendar-jump input{width:auto;max-width:100%;border:1px solid #dfe8e2;border-radius:10px;padding:9px 10px;background:#fff;color:#334b3c;font:inherit;font-size:.82rem}
.calendar-jump button{font-size:.8rem;padding:0 14px}
.calendar-workspace .calendar-day{min-height:56px}
.calendar-legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:18px;color:#75867b;font-size:.72rem}
.calendar-legend span{display:inline-flex;align-items:center;gap:6px}.calendar-legend i{width:6px;height:6px;border-radius:50%}
.calendar-history-note{font-size:.75rem;color:#7a877f;line-height:1.6;margin-top:20px;border-top:1px solid #e7ede8;padding-top:14px}
.calendar-workspace .calendar-task-item{grid-template-columns:minmax(0,1fr) auto;gap:8px}
.calendar-workspace .calendar-task-priority{grid-column:1/-1;justify-self:start}
.calendar-task-info .subject-name{overflow-wrap:anywhere}
body.dark-mode .calendar-jump input,body.dark-mode .calendar-navigation a,body.dark-mode .calendar-jump button{background:#1e293b;color:#e2e8f0;border-color:#475569}
body.dark-mode .calendar-workspace .calendar,body.dark-mode .calendar-workspace .calendar-task-item,body.dark-mode .calendar-workspace .empty-state{background:#172235;color:#e2e8f0;border-color:#334155}
body.dark-mode .calendar-day.selected:not(.today){background:#344d40}
.calendar-workspace .selected-day-tasks .panel-header{margin-bottom:20px}
.calendar-workspace .selected-day-tasks .panel-title{font-size:1.2rem;letter-spacing:-.025em}
.calendar-workspace .subject-breakdown{display:grid;gap:10px}
.calendar-task-group{overflow:hidden;border:1px solid #e8eeea;border-radius:16px;background:#fbfcfb;transition:border-color .18s ease,background .18s ease}
.calendar-task-group:hover,.calendar-task-group[open]{border-color:#d5e2d9;background:#fff}
.calendar-task-group summary{display:grid;grid-template-columns:minmax(0,1fr) auto 18px;align-items:center;gap:12px;min-height:70px;padding:13px 15px;cursor:pointer;list-style:none}
.calendar-task-group summary::-webkit-details-marker{display:none}
.calendar-group-copy{display:grid;gap:4px;min-width:0}
.calendar-group-title{overflow:hidden;color:#35433a;font-size:.88rem;font-weight:750;text-overflow:ellipsis;white-space:nowrap}
.calendar-group-description{color:#849087;font-size:.74rem;line-height:1.4}
.calendar-group-count{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:30px;padding:0 10px;border:1px solid #e1eae4;border-radius:999px;background:#f4f8f5;color:#597361;font-size:.72rem;font-weight:750}
.calendar-group-chevron{width:8px;height:8px;border-right:1.5px solid #829087;border-bottom:1.5px solid #829087;transform:rotate(45deg) translateY(-2px);transition:transform .18s ease}
.calendar-task-group[open] .calendar-group-chevron{transform:rotate(225deg) translate(-2px,-1px)}
.calendar-task-group summary:focus-visible{outline:3px solid #9ab7a6;outline-offset:-3px;border-radius:15px}
.calendar-task-list{display:grid;gap:8px;padding:0 12px 12px}
.calendar-workspace .calendar-task-item{grid-template-columns:minmax(0,1fr) auto;gap:12px;min-height:64px;padding:11px 13px;border-radius:12px}
.calendar-workspace .calendar-task-info{gap:6px}
.calendar-workspace .calendar-task-info .subject-name{font-size:.86rem}
.calendar-workspace .calendar-task-priority{grid-column:auto;justify-self:start;padding:4px 8px;font-size:.66rem}
.calendar-workspace .calendar-task-status{padding:5px 9px;font-size:.68rem}
.calendar-workspace .calendar-task-info .subject-count{font-size:.73rem}
.calendar-workspace .calendar-history-note{margin-top:18px}
body.dark-mode .calendar-task-group{border-color:#334155;background:#172235}
body.dark-mode .calendar-task-group:hover,body.dark-mode .calendar-task-group[open]{border-color:#4b6654;background:#1b2a21}
body.dark-mode .calendar-group-title{color:#e2e8f0}
body.dark-mode .calendar-group-description{color:#a7b3aa}
body.dark-mode .calendar-group-count{border-color:#405646;background:#263a2d;color:#cee0d1}
body.dark-mode .calendar-task-list .calendar-task-item{background:#1b2a21}
@media(max-width:1050px){.calendar-workspace{grid-template-columns:1fr}.calendar-workspace .calendar-card{max-width:800px;margin:auto;width:100%}}
@media(max-width:480px){.calendar-workspace .calendar-card{padding:16px}.calendar-workspace .calendar-day{min-height:42px}.calendar-month-heading{flex-wrap:wrap}.calendar-jump{gap:8px}}

</style>
<link rel="stylesheet" href="assets/css/calendar-polish.css?v=1">
</head>
<body class="dashboard-body">
<?php include 'includes/sidebar.php'; ?>
<?php include 'includes/topbar.php'; ?>

<main class="dashboard-main">
<div class="dashboard-container">
    <div class="greeting-section">
        <div class="greeting-content">
            <h1 class="greeting-title">📅 Calendar</h1>
            <p class="greeting-subtitle">Select a date to instantly see tasks completed or due that day.</p>
        </div>
    </div>

    <div class="calendar-workspace">
    <section class="dashboard-card fade-in calendar-card">
        <div class="panel-header">
            <div class="calendar-month-heading"><div><span class="calendar-eyebrow">YOUR TIMELINE</span><h2 class="panel-title"><?php echo $selected->format('F Y'); ?></h2></div>
            <nav class="calendar-navigation" aria-label="Month navigation">
                <?php if ($previous_month >= '1000-01-01'): ?><a href="?date=<?php echo $previous_month; ?>" aria-label="Previous month" title="Previous month">&lsaquo;</a><?php endif; ?>
                <a class="calendar-today" href="?date=<?php echo $today_date; ?>">Today</a>
                <?php if ($next_month <= '9999-12-31'): ?><a href="?date=<?php echo $next_month; ?>" aria-label="Next month" title="Next month">&rsaquo;</a><?php endif; ?>
            </nav></div>
            <form class="calendar-jump" method="get"><label for="calendar-date">Jump to date</label><input id="calendar-date" name="date" type="date" min="1000-01-01" max="9999-12-31" value="<?php echo $selected_date; ?>" required><button type="submit">Go</button></form>
        </div>
        <div class="calendar">
            <div class="calendar-weekdays">
                <?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $wd): ?>
                    <div class="weekday"><?php echo $wd; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="calendar-days">
                <?php
                $day_offset = $first_day - 1;
                for($i = 0; $i < $day_offset; $i++): ?>
                    <div class="calendar-day empty"></div>
                <?php endfor;

                $events_by_day = [];
                $tasks_by_date = [];
                if ($month_events) {
                    while ($ev = mysqli_fetch_assoc($month_events)) {
                        $deadline_date = substr($ev['deadline'], 0, 10);
                        if (substr($deadline_date, 0, 7) === sprintf('%04d-%02d', $current_year, $current_month)) {
                            $events_by_day[(int)substr($deadline_date, 8, 2)][] = $ev;
                            $tasks_by_date[$deadline_date]['due'][] = $ev;
                        }
                        if (!empty($ev['completed_at'])) {
                            $completed_date = substr($ev['completed_at'], 0, 10);
                            if (substr($completed_date, 0, 7) === sprintf('%04d-%02d', $current_year, $current_month)) {
                                $tasks_by_date[$completed_date]['completed'][] = $ev;
                                $events_by_day[(int)substr($completed_date, 8, 2)][] = $ev;
                            }
                        }
                    }
                }

                foreach($plannerByDate as $blockDate=>$dayBlocks) {
                    $blockDay=(int)substr($blockDate,8,2);
                    $events_by_day[$blockDay]=array_merge([['priority'=>'Study']],$events_by_day[$blockDay]??[]);
                }
                for($d = 1; $d <= $days_in_month; $d++):
                    $is_today = sprintf('%04d-%02d-%02d', $current_year, $current_month, $d) === $today_date;
                    $day_events = $events_by_day[$d] ?? [];
                ?>
                                        <?php $calendar_date = sprintf('%04d-%02d-%02d', $current_year, $current_month, $d); ?>
                    <button type="button" class="calendar-day <?php echo $is_today ? 'today' : ''; ?> <?php echo $calendar_date === $selected_date ? 'selected' : ''; ?>" data-date="<?php echo $calendar_date; ?>" aria-label="Show tasks for <?php echo date('F j, Y', strtotime($calendar_date)); ?>">
                        <div class="day-number"><?php echo $d; ?></div>
                        <?php if(!empty($day_events)): ?>
                            <div class="day-event-indicator">
                                <?php foreach(array_slice($day_events, 0, 3) as $ev): ?>
                                    <div class="event-dot" style="background:<?php
                                        echo $ev['priority'] === 'Study' ? '#8b79b0' : ($ev['priority'] === 'High' ? '#ef4444' : ($ev['priority'] === 'Medium' ? '#f59e0b' : '#16a34a'));
                                    ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </button>
                <?php endfor; ?>
                <?php $remaining = 7 - (($day_offset + $days_in_month) % 7); if($remaining < 7): for($i = 0; $i < $remaining; $i++): ?>
                    <div class="calendar-day empty"></div>
                <?php endfor; endif; ?>
            </div>
        </div>
        <div class="calendar-legend"><span><i style="background:#ef4444"></i>High priority</span><span><i style="background:#f59e0b"></i>Medium</span><span><i style="background:#16a34a"></i>Low</span><span><i style="background:#8b79b0"></i>Study block</span></div>
    </section>

    <section class="dashboard-card fade-in selected-day-tasks" aria-live="polite" aria-atomic="true">
        <div class="panel-header"><h3 class="panel-title" id="selected-date-title">Tasks for <?php echo date('F j, Y', strtotime($selected_date)); ?></h3><p id="selected-date-summary">Choose a date to view your task activity.</p></div>
        <div class="calendar-day-overview" role="group" aria-label="Activity summary for selected date">
            <div class="calendar-day-stat"><strong id="calendar-completed-count">0</strong><span>Completed</span></div>
            <div class="calendar-day-stat"><strong id="calendar-deadline-count">0</strong><span>Due</span></div>
            <div class="calendar-day-stat"><strong id="calendar-study-count">0</strong><span>Study blocks</span></div>
        </div>
        <div class="subject-breakdown" id="selected-date-tasks"></div>
        <p class="calendar-history-note">Tasks appear on their due date and completion date. Study blocks show your saved plans in Philippine time. <a href="study_planner.php">Open study planner &rarr;</a></p>
    </section>
    </div>
    <section class="dashboard-card fade-in calendar-upcoming">
        <div class="panel-header">
            <h3 class="panel-title"><i class="app-icon" data-lucide="clipboard-list" aria-hidden="true"></i> Upcoming Deadlines</h3>
        </div>
        <div class="subject-breakdown">
            <?php if(mysqli_num_rows($all_deadlines) > 0): ?>
                <?php while($dl = mysqli_fetch_assoc($all_deadlines)): ?>
                    <div class="deadline-item">
                        <span class="deadline-date"><?php echo date('M j', strtotime($dl['deadline'])); ?><?php if (!empty($dl['deadline_time'])): ?><small><?php echo date('g:i A', strtotime($dl['deadline_time'])); ?></small><?php endif; ?></span>
                        <div style="flex:1">
                            <span class="subject-name"><?php echo htmlspecialchars($dl['title']); ?></span>
                            <span class="subject-count"><?php echo htmlspecialchars($dl['status']); ?></span>
                        </div>
                        <span class="priority-badge priority-<?php echo strtolower($dl['priority']); ?>"><?php echo htmlspecialchars($dl['priority']); ?></span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state"><i class="app-icon" data-lucide="circle-check" aria-hidden="true"></i><span>No upcoming deadlines. You’re all caught up.</span></div>
            <?php endif; ?>
        </div>
    </section>

</div>
</main>
<script>
const calendarTasks = <?php echo json_encode($tasks_by_date, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const calendarStudyBlocks = <?php echo json_encode($plannerByDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const selectedDateTitle = document.getElementById('selected-date-title');
const selectedDateSummary = document.getElementById('selected-date-summary');
const selectedDateTasks = document.getElementById('selected-date-tasks');
const completedCount = document.getElementById('calendar-completed-count');
const deadlineCount = document.getElementById('calendar-deadline-count');
const studyCount = document.getElementById('calendar-study-count');
const formatCalendarDate = date => new Date(`${date}T12:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
function createTaskGroup(label, description, items, renderItem, openByDefault) {
    if (!items.length) return;
    const group = document.createElement('details');
    group.className = 'calendar-task-group';
    group.open = openByDefault;

    const summary = document.createElement('summary');
    const copy = document.createElement('span');
    copy.className = 'calendar-group-copy';
    const title = document.createElement('span');
    title.className = 'calendar-group-title';
    title.textContent = label;
    const detail = document.createElement('span');
    detail.className = 'calendar-group-description';
    detail.textContent = description;
    copy.append(title, detail);

    const count = document.createElement('span');
    count.className = 'calendar-group-count';
    count.textContent = String(items.length);
    count.setAttribute('aria-label', `${items.length} ${items.length === 1 ? 'item' : 'items'}`);
    const chevron = document.createElement('span');
    chevron.className = 'calendar-group-chevron';
    chevron.setAttribute('aria-hidden', 'true');
    summary.append(copy, count, chevron);

    const list = document.createElement('div');
    list.className = 'calendar-task-list';
    for (const item of items) list.append(renderItem(item));
    group.append(summary, list);
    selectedDateTasks.append(group);
}

function createTaskRow(task) {
    const row = document.createElement('a');
    row.className = 'calendar-task-item';
    row.href = `view_task.php?id=${encodeURIComponent(task.id)}`;

    const info = document.createElement('div');
    info.className = 'calendar-task-info';
    const title = document.createElement('span');
    title.className = 'subject-name';
    title.textContent = task.title;
    if (task.deadline_time) {
        const dueTime = document.createElement('span');
        dueTime.className = 'subject-count';
        dueTime.textContent = `Due at ${new Date(`2000-01-01T${task.deadline_time}`).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`;
        info.append(title, dueTime);
    } else {
        info.append(title);
    }
    const priority = document.createElement('span');
    priority.className = `calendar-task-priority priority-${String(task.priority || '').toLowerCase()}`;
    priority.textContent = `${task.priority} priority`;
    info.append(priority);

    const status = document.createElement('span');
    const statusClass = String(task.status || '').toLowerCase().replace(/[^a-z]+/g, '-');
    status.className = `calendar-task-status status-${statusClass}`;
    status.textContent = task.status;
    row.append(info, status);
    return row;
}

function renderTasksForDate(date) {
    selectedDateTitle.textContent = `Tasks for ${formatCalendarDate(date)}`;
    selectedDateTasks.replaceChildren();
    const activity = calendarTasks[date] || {};
    const completed = activity.completed || [];
    const completedIds = new Set(completed.map(task => String(task.id)));
    const due = (activity.due || []).filter(task => !completedIds.has(String(task.id)));
    const groups = [['Completed this day', completed], ['Due this day', due]];
    const studyBlocks = calendarStudyBlocks[date] || [];
    const total = completed.length + due.length + studyBlocks.length;
    completedCount.textContent = String(completed.length);
    deadlineCount.textContent = String(due.length);
    studyCount.textContent = String(studyBlocks.length);
    selectedDateSummary.textContent = total
        ? `${total} ${total === 1 ? 'item' : 'items'} on this day`
        : 'A clear day. Enjoy the breathing room.';

    for (const [label, tasks] of groups) {
        createTaskGroup(
            label,
            label === 'Completed this day' ? 'Finished tasks from this date' : 'Tasks scheduled for this date',
            tasks,
            createTaskRow,
            total <= 3
        );
    }

    createTaskGroup('Planned study blocks', 'Saved sessions in your study plan', studyBlocks, block => {
            const row = document.createElement('a');
            row.className = 'calendar-task-item';
            row.href = `study_planner.php?edit=${encodeURIComponent(block.id)}#block-form`;

            const info = document.createElement('div');
            info.className = 'calendar-task-info';
            const title = document.createElement('span');
            title.className = 'subject-name';
            title.textContent = block.title;
            const details = document.createElement('span');
            details.className = 'subject-count';
            details.textContent = `${block.start_time.slice(0, 5)} - ${block.end_time.slice(0, 5)} · ${block.break_minutes} min break`;
            info.append(title, details);

            const status = document.createElement('span');
            status.className = 'calendar-task-status';
            status.textContent = 'Planned';
            row.append(info, status);
            return row;
    }, total <= 3);

    if (date >= <?php echo json_encode($today_date); ?>) {
        const addBlock = document.createElement('a');
        addBlock.className = 'calendar-plan-link';
        addBlock.href = `study_planner.php?date=${encodeURIComponent(date)}#block-form`;
        addBlock.textContent = 'Plan a study block for this day';
        selectedDateTasks.append(addBlock);
    }

    if (!total) {
        const empty = document.createElement('div');
        empty.className = 'calendar-empty-state';
        const iconWrap = document.createElement('span');
        iconWrap.className = 'calendar-empty-icon';
        const icon = document.createElement('i');
        icon.className = 'app-icon';
        icon.dataset.lucide = 'calendar-check';
        icon.setAttribute('aria-hidden', 'true');
        iconWrap.append(icon);
        const copy = document.createElement('span');
        copy.className = 'calendar-empty-copy';
        const title = document.createElement('strong');
        title.textContent = 'Nothing scheduled';
        const description = document.createElement('span');
        description.textContent = 'No tasks due, completed, or planned on this date.';
        copy.append(title, description);
        empty.append(iconWrap, copy);
        selectedDateTasks.append(empty);
        if (window.lucide) {
            window.lucide.createIcons();
        } else {
            document.addEventListener('DOMContentLoaded', () => {
                if (window.lucide) window.lucide.createIcons();
            }, { once: true });
        }
    }
}

document.querySelectorAll('.calendar-day[data-date]').forEach(day => {
    day.setAttribute('aria-pressed', day.classList.contains('selected') ? 'true' : 'false');
    day.addEventListener('click', () => {
        document.querySelector('.calendar-day.selected')?.classList.remove('selected');
        document.querySelector('.calendar-day[aria-pressed="true"]')?.setAttribute('aria-pressed', 'false');
        day.classList.add('selected');
        day.setAttribute('aria-pressed', 'true');
        renderTasksForDate(day.dataset.date);
        document.getElementById('calendar-date').value = day.dataset.date;
        const url = new URL(location.href);
        url.searchParams.set('date', day.dataset.date);
        history.replaceState(null, '', url);

    });
});
renderTasksForDate(<?php echo json_encode($selected_date); ?>);
</script>
</body>
</html>
