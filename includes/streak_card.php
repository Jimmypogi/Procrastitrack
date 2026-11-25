<?php
require_once __DIR__.'/../lib/streak.php';
$streakUser=(int)$_SESSION['user_id'];
$streakStmt=$conn->prepare('SELECT activity_date FROM user_activity_days WHERE user_id=? ORDER BY activity_date');
$streakStmt->bind_param('i',$streakUser); $streakStmt->execute();
$streakDates=array_column($streakStmt->get_result()->fetch_all(MYSQLI_ASSOC),'activity_date');
$streakStmt->close();
$streakToday=new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
$streak=app_streak_summary($streakDates,$streakToday->format('Y-m-d'));
$currentMonth = $streakToday->modify('first day of this month');
$firstActive = array_key_first($streak['active']);
$firstMonth = $firstActive ? (new DateTimeImmutable($firstActive, $streakToday->getTimezone()))->modify('first day of this month') : $currentMonth;
$requestedMonth = $_GET['streak_month'] ?? '';
$calendarOpen = is_string($requestedMonth) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $requestedMonth);
$month = $calendarOpen ? new DateTimeImmutable($requestedMonth . '-01', $streakToday->getTimezone()) : $currentMonth;
$month = max($firstMonth, min($month, $currentMonth));
$prevMonth = $month > $firstMonth ? $month->modify('-1 month')->format('Y-m') : null;
$nextMonth = $month < $currentMonth ? $month->modify('+1 month')->format('Y-m') : null;
$leadingDays = (int)$month->format('N') - 1;
$daysInMonth = (int)$month->format('t');
$streakGoal=7;
foreach([7,14,30,60,100,365] as $goal) { $streakGoal=$goal; if($goal>$streak['current']) break; }
if($streak['current']>=365) $streakGoal=(intdiv($streak['current'],100)+1)*100;
?>
<section class="daily-streak" aria-labelledby="streak-title">
<div class="streak-main">
<div class="streak-heading"><span class="streak-flame"><i class="app-icon" data-lucide="flame" aria-hidden="true"></i></span><div><p class="streak-eyebrow">ONE DAY AT A TIME</p><h2 id="streak-title">Your daily streak</h2></div><?php if (isset($streak['active'][$streakToday->format('Y-m-d')])): ?><span class="streak-today">Today counted</span><?php endif; ?></div>
<div class="streak-number"><strong><?php echo $streak['current']; ?></strong><div><b><?php echo $streak['current']===1?'day':'days'; ?> in a row</b><p><?php echo $streak['current']===1?'A fresh start. Come back tomorrow to keep it going.':'You are showing up for yourself. Keep taking small steps.'; ?></p></div></div>
<div class="streak-records"><span>Personal best <b><?php echo $streak['best']; ?> days</b></span><span>Active days <b><?php echo $streak['total']; ?></b></span></div>
</div>
<div class="streak-week">
<div class="streak-week-title"><h3>Last 7 days</h3><span>Philippine time</span></div>
<div class="streak-days">
<?php for($i=6;$i>=0;$i--): $day=$streakToday->modify("-$i days"); $key=$day->format('Y-m-d'); $active=isset($streak['active'][$key]); ?>
<div class="streak-day <?php echo $active?'is-active':''; ?>" <?php echo $i===0?'aria-current="date"':''; ?> aria-label="<?php echo $day->format('F j, Y').($active?': active':': no visit recorded'); ?>">
<span><?php echo $day->format('D'); ?></span><b><?php echo $active?'&#10003;':$day->format('j'); ?></b><small><?php echo $i===0?'Today':$day->format('M j'); ?></small></div>
<?php endfor; ?>
</div>
<div class="streak-goal"><span>Next milestone</span><b><?php echo $streakGoal; ?> days</b></div>
<progress max="<?php echo $streakGoal; ?>" value="<?php echo $streak['current']; ?>" aria-label="Progress toward next streak milestone"></progress>
<p class="streak-rule">Visit any student page while signed in to count the day. Missing a day starts a new streak; your best stays saved. Tracking begins with this feature.</p>
</div>
<details class="streak-calendar" id="streak-calendar"<?php echo $calendarOpen ? ' open' : ''; ?>>
    <summary><i class="app-icon" data-lucide="calendar-days" aria-hidden="true"></i> Streak calendar <span aria-hidden="true">⌄</span></summary>
    <div class="streak-calendar-panel">
        <nav class="streak-calendar-nav" aria-label="Streak calendar months">
            <?php if ($prevMonth): ?><a href="?streak_month=<?php echo $prevMonth; ?>#streak-calendar" aria-label="Previous month">‹</a><?php else: ?><span aria-hidden="true">‹</span><?php endif; ?>
            <strong><?php echo $month->format('F Y'); ?></strong>
            <?php if ($nextMonth): ?><a href="?streak_month=<?php echo $nextMonth; ?>#streak-calendar" aria-label="Next month">›</a><?php else: ?><span aria-hidden="true">›</span><?php endif; ?>
        </nav>
        <table class="streak-calendar-grid" aria-label="<?php echo $month->format('F Y'); ?> streak activity">
            <thead><tr><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday): ?><th scope="col"><?php echo $weekday; ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php for ($row = 0; $row < (int)ceil(($leadingDays + $daysInMonth) / 7); $row++): ?>
                <tr>
                <?php for ($column = 0; $column < 7; $column++):
                    $number = $row * 7 + $column - $leadingDays + 1;
                    if ($number < 1 || $number > $daysInMonth): ?>
                        <td></td>
                    <?php else:
                        $day = $month->setDate((int)$month->format('Y'), (int)$month->format('n'), $number);
                        $key = $day->format('Y-m-d');
                        $active = isset($streak['active'][$key]);
                        $future = $day > $streakToday;
                        $state = $active ? 'Active' : ($future ? 'Upcoming' : 'No visit recorded');
                    ?>
                        <td><time datetime="<?php echo $key; ?>" class="<?php echo $active ? 'is-active' : ($future ? 'is-future' : ''); ?>"<?php echo $day == $streakToday ? ' aria-current="date"' : ''; ?> aria-label="<?php echo $day->format('F j, Y') . ': ' . $state; ?>"><?php echo $number; ?></time></td>
                    <?php endif;
                endfor; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        <p class="streak-calendar-legend"><span><b aria-hidden="true"></b> Active day</span><span><b aria-hidden="true"></b> Today</span></p>
    </div>
</details>
</section>
