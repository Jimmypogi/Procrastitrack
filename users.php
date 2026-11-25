<?php
require_once __DIR__ . '/auth.php';
admin_require_role(['superadmin','admin']);
require_once __DIR__ . '/../config/database.php';
if (!isset($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], $_POST['csrf'] ?? '')) {
    $id = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id > 0 && in_array($action, ['activate','deactivate'], true)) {
        $active = $action === 'activate' ? 1 : 0;
        mysqli_query($conn, "UPDATE users SET is_active=$active WHERE id=$id");
        $message = $active ? 'User account activated.' : 'User account paused.';
    }
}
$search = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';
$where = '';
if ($search !== '') { $safe = mysqli_real_escape_string($conn, $search); $where = "WHERE fullname LIKE '%$safe%' OR email LIKE '%$safe%'"; }
$page=max(1,filter_var($_GET['page']??1,FILTER_VALIDATE_INT)?:1);
$count=(int)$conn->query("SELECT COUNT(*) FROM users $where")->fetch_row()[0];
$pages=max(1,(int)ceil($count/20));$page=min($page,$pages);$offset=($page-1)*20;
$users = mysqli_query($conn, "SELECT id, fullname, email, role, is_active, registration_date, last_activity, (last_activity >= DATE_SUB(NOW(),INTERVAL 2 MINUTE)) online_now FROM users $where ORDER BY id DESC LIMIT 20 OFFSET $offset");
require_once __DIR__ . '/_layout.php';
admin_page_start('User management', 'users');
?><section class="card"><div class="card-title"><div><h2>Student accounts</h2><p class="muted">Find students, review their activity summary, and manage account access.</p></div><span class="badge good"><?php echo admin_query_value($conn, "SELECT COUNT(*) value FROM users WHERE is_active=1"); ?> enabled accounts</span></div><?php if ($message): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?><p class="muted">Online now: activity in the last 2 minutes in a visible student tab. Idle or closed tabs may take up to 2 minutes to appear offline. Times use Philippine time; older join dates may not have been recorded.</p><form class="toolbar" method="get"><input name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email"><button class="button secondary" type="submit">Search</button><?php if ($search): ?><a class="button secondary" href="users.php">Clear</a><?php endif; ?></form><div class="table-wrap"><table class="admin-table"><thead><tr><th>Student</th><th>Role</th><th>Status</th><th>Joined</th><th>Last activity</th><th>Action</th></tr></thead><tbody><?php if ($users && mysqli_num_rows($users)): while ($user = mysqli_fetch_assoc($users)): ?><tr><td><strong><?php echo htmlspecialchars($user['fullname'] ?: 'Unnamed student'); ?></strong><br><span class="muted"><?php echo htmlspecialchars($user['email']); ?></span></td><td><?php echo htmlspecialchars(ucfirst($user['role'] ?? 'student')); ?></td><td><span class="badge <?php echo !$user['is_active']?'risk':($user['online_now']?'good':''); ?>"><?php echo !$user['is_active']?'Paused':($user['online_now']?'Online now':'Offline'); ?></span></td><td><?php echo $user['registration_date'] ? htmlspecialchars(date('M j, Y',strtotime($user['registration_date']))) : '<span class="muted">Not recorded</span>'; ?></td><td><?php echo $user['last_activity'] ? htmlspecialchars(date('M j, Y, g:i A',strtotime($user['last_activity']))) : '<span class="muted">No visit recorded</span>'; ?></td><td><div class="student-row-actions"><a class="button secondary" href="student_summary.php?id=<?php echo (int)$user['id']; ?>">View summary</a><a class="button secondary" href="student_summary.php?id=<?php echo (int)$user['id']; ?>&amp;download=csv">Export CSV</a><form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['admin_csrf']); ?>"><input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>"><input type="hidden" name="action" value="<?php echo $user['is_active'] ? 'deactivate' : 'activate'; ?>"><button class="button <?php echo $user['is_active'] ? 'danger' : 'secondary'; ?>" type="submit"><?php echo $user['is_active'] ? 'Pause access' : 'Activate'; ?></button></form></div></td></tr><?php endwhile; else: ?><tr><td colspan="6" class="muted">No users found.</td></tr><?php endif; ?></tbody></table></div><nav class="admin-pagination" aria-label="Student pages"><span><?php echo $count; ?> students &middot; Page <?php echo $page; ?> of <?php echo $pages; ?></span><?php if($page>1): ?><a class="button secondary" href="?<?php echo htmlspecialchars(http_build_query(['q'=>$search,'page'=>$page-1])); ?>">Previous</a><?php endif; ?><?php if($page<$pages): ?><a class="button secondary" href="?<?php echo htmlspecialchars(http_build_query(['q'=>$search,'page'=>$page+1])); ?>">Next</a><?php endif; ?></nav></section><script>
setInterval(async()=>{
 if(document.hidden||document.querySelector('.table-wrap:focus-within'))return;
 try{const r=await fetch(location.href,{cache:'no-store'});if(!r.ok)return;const doc=new DOMParser().parseFromString(await r.text(),'text/html');const table=doc.querySelector('.table-wrap');if(table)document.querySelector('.table-wrap').replaceWith(table);}catch(e){}
},45000);
</script><?php admin_page_end(); ?>
