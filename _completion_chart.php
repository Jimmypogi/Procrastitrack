<?php
$counts=array_column($weekly,'count');
$ceiling=max(4,(int)(ceil(max($counts)/4)*4));
$points=[];
foreach($weekly as $i=>$week) $points[]=[48+$i*128,230-($week['count']/$ceiling)*180];
$path='M '.$points[0][0].' '.$points[0][1];
for($i=1;$i<count($points);$i++) {
 [$x0,$y0]=$points[$i-1];[$x1,$y1]=$points[$i];$mid=($x0+$x1)/2;
 $path.=" C $mid $y0 $mid $y1 $x1 $y1";
}
$area=$path.' L 688 230 L 48 230 Z';
$change=$counts[5]-$counts[4];
?>
<div class="completion-terminal">
<div class="terminal-summary"><div><span class="terminal-label">COMPLETED TASKS</span><div class="terminal-value"><?php echo array_sum($counts); ?><small>over 6 weeks</small></div></div><span class="terminal-change <?php echo $change<0?'down':''; ?>"><?php echo $change>0?'+':'';echo $change; ?> vs previous week</span></div>
<p class="terminal-readout" id="trendReadout" aria-live="polite">Hover, tap, or focus a point to inspect a week.</p>
<svg class="completion-chart" viewBox="0 0 750 280" role="group" aria-label="Tasks completed in six consecutive seven-day periods">
<defs><linearGradient id="completionArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#50c7a0" stop-opacity=".32"/><stop offset="100%" stop-color="#50c7a0" stop-opacity=".02"/></linearGradient></defs>
<?php for($i=0;$i<=4;$i++):$y=230-$i*45; ?><line x1="48" x2="688" y1="<?php echo $y; ?>" y2="<?php echo $y; ?>" stroke="#2b4039" stroke-dasharray="3 5"/><text x="705" y="<?php echo $y+4; ?>" fill="#91a99f" font-size="12"><?php echo $ceiling*$i/4; ?></text><?php endfor; ?>
<path d="<?php echo $area; ?>" fill="#edf3ee"/>
<path d="<?php echo $path; ?>" fill="none" stroke="#65d8af" stroke-width="3" stroke-linecap="round"/>
<?php foreach($points as $i=>$point):$label=$weekly[$i]['label'].' - '.$weekly[$i]['end_label'].': '.$counts[$i].' completed'; ?>
<g class="terminal-point" tabindex="0" aria-label="<?php echo htmlspecialchars($label,ENT_QUOTES); ?>" data-label="<?php echo htmlspecialchars($label,ENT_QUOTES); ?>">
<line class="terminal-crosshair" x1="<?php echo $point[0]; ?>" x2="<?php echo $point[0]; ?>" y1="40" y2="230" stroke="#b8decf" stroke-dasharray="4 4"/>
<circle cx="<?php echo $point[0]; ?>" cy="<?php echo $point[1]; ?>" r="18" fill="transparent"/>
<circle cx="<?php echo $point[0]; ?>" cy="<?php echo $point[1]; ?>" r="5" fill="#152a22" stroke="#65d8af" stroke-width="3"/>
<title><?php echo htmlspecialchars($label); ?></title>
</g><text x="<?php echo $point[0]; ?>" y="260" text-anchor="middle" fill="#91a99f" font-size="12"><?php echo htmlspecialchars($weekly[$i]['label']); ?></text>
<?php endforeach; ?>
</svg>
<div class="terminal-footer"><span><i></i>Weekly completions</span><span>7-day totals · Philippine time</span></div>
</div>
<details class="terminal-data"><summary>View exact numbers</summary><table><thead><tr><th scope="col">Period</th><th scope="col">Completed</th></tr></thead><tbody><?php foreach($weekly as $week): ?><tr><th scope="row"><?php echo htmlspecialchars($week['label'].' - '.$week['end_label']); ?></th><td><?php echo $week['count']; ?></td></tr><?php endforeach; ?></tbody></table></details>
<style>
.completion-terminal{background:linear-gradient(125deg,#172d25,#101e19);border:1px solid #31483d;border-radius:18px;padding:22px;color:#e3f0e9;min-width:0}.terminal-summary{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.terminal-label{font-size:.65rem;letter-spacing:.14em;color:#9db7a9;font-weight:700}.terminal-value{font-size:2.1rem;font-weight:750;margin-top:8px}.terminal-value small{font-size:.75rem;font-weight:400;margin-left:12px;color:#9db7a9}.terminal-change{font-size:.7rem;color:#82d7b4;background:#294638;border:1px solid #3b5b49;padding:7px 10px;border-radius:8px}.terminal-change.down{color:#e7b9a2;background:#49372e;border-color:#665044}.terminal-readout{font-size:.76rem;color:#b7cebf;min-height:2.5em;margin:18px 0 0}.completion-chart{display:block;width:100%;height:auto;overflow:visible}.terminal-crosshair{opacity:0}.terminal-point:hover .terminal-crosshair,.terminal-point:focus .terminal-crosshair{opacity:1}.terminal-point{cursor:crosshair}.terminal-point:focus{outline:none}.terminal-point:focus circle:last-of-type{stroke:#fff;stroke-width:4}.terminal-footer{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:.65rem;color:#93ac9d;border-top:1px solid #304438;padding-top:14px}.terminal-footer i{display:inline-block;width:6px;height:6px;border-radius:50%;background:#65d8af;margin-right:6px}.terminal-data{margin-top:14px;font-size:.8rem;color:#718275}.terminal-data summary{cursor:pointer}.terminal-data table{width:100%;margin-top:12px;text-align:left}.terminal-data th,.terminal-data td{padding:8px;font-size:.78rem}@media(max-width:550px){.completion-terminal{padding:14px}.completion-chart{min-height:180px}.terminal-change{font-size:.65rem}}
</style>
<script>
document.querySelectorAll('.terminal-point').forEach(function(point){
 function inspect(){document.getElementById('trendReadout').textContent=point.dataset.label;}
 point.addEventListener('pointerenter',inspect);point.addEventListener('focus',inspect);point.addEventListener('click',inspect);
});
</script>
