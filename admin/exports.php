<?php
require_once __DIR__.'/auth.php';
admin_require_role(['superadmin','admin','analyst']);
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/_layout.php';
admin_page_start('Export summary','export');
?>
<section class="card export-center"><div class="card-title"><div><h2>Choose a report</h2><p class="muted">Download a CSV file for spreadsheets. Exports use the latest saved records.</p></div><span class="badge good">CSV format</span></div>
<div class="export-option"><div><span class="eyebrow">ORGANIZATION</span><h3>Overall activity summary</h3><p class="muted">Student counts, tasks, study sessions, and aggregated behavior signals.</p><small class="muted">All recorded history. Small classification groups are suppressed.</small></div><a class="button primary" href="export.php">Download summary</a></div>
<?php if(in_array($_SESSION['admin_role'],['admin','superadmin'],true)): ?>
<div class="export-option"><div><span class="eyebrow">INDIVIDUAL STUDENT</span><h3>Student activity summary</h3><p class="muted">Choose a student to review totals before exporting their report.</p><small class="muted">Includes student identity. Personal reflection text is excluded.</small></div><a class="button secondary" href="users.php">Choose student</a></div>
<?php endif; ?>
<footer class="export-note">CSV files reflect the data at download time. Dates use Philippine time.</footer></section>
<?php admin_page_end(); ?>
