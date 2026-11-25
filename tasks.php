<?php

session_start();

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit();
}

include 'config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__.'/lib/task_priority.php';
$effectivePriority=task_priority_sql();
if (($_GET['status'] ?? '') === 'Completed') {
    header('Location: profile.php#completed-tasks');
    exit();
}

$user_id = $_SESSION['user_id'];
$completionCelebration = $_SESSION['task_completion_celebration'] ?? null;
unset($_SESSION['task_completion_celebration']);

$search = '';
$searchSql = '';
$statusFilter = '';
$priorityFilter = '';

if(isset($_GET['search']) && trim($_GET['search']) !== ''){
    $search = mysqli_real_escape_string($conn, $_GET['search']);
    $searchSql = " AND (title LIKE '%$search%' OR $effectivePriority LIKE '%$search%' OR status LIKE '%$search%')";
}
if(isset($_GET['status']) && $_GET['status'] !== 'all'){
    $statusFilter = mysqli_real_escape_string($conn, $_GET['status']);
    $searchSql .= " AND status='$statusFilter'";
}
if(isset($_GET['priority']) && $_GET['priority'] !== 'all'){
    $priorityFilter = mysqli_real_escape_string($conn, $_GET['priority']);
    $searchSql .= " AND $effectivePriority='$priorityFilter'";
}
$total_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id='$user_id'"));
$completed_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id='$user_id' AND status='Completed'"));
$inprogress_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id='$user_id' AND status='In Progress'"));
$pending_tasks = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id='$user_id' AND status='Pending'"));
$completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

$overdue = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id='$user_id' AND status!='Completed' AND " . app_task_overdue_sql()));
$highPriority = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM tasks WHERE user_id='$user_id' AND $effectivePriority='High' AND status!='Completed'"));

$whereClause = "WHERE user_id='$user_id' AND status<>'Completed'";
if($searchSql) $whereClause .= $searchSql;

$result = mysqli_query($conn, "SELECT *, $effectivePriority AS displayed_priority FROM tasks $whereClause ORDER BY 
    CASE status WHEN 'Pending' THEN 0 WHEN 'In Progress' THEN 1 WHEN 'Completed' THEN 2 ELSE 3 END,
    FIELD(displayed_priority,'High','Medium','Low'), deadline ASC, id DESC");


function getOverdueClass($deadline, $status, $deadlineTime = null){
    return app_task_deadline_class($deadline, $deadlineTime, $status);
}

function getPriorityBadge($priority){
    $cls = strtolower($priority);
    return "<span class=\"s-tags $cls\">" . htmlspecialchars($priority) . "</span>";
}

function getStatusBadge($status){
    $cls = strtolower(str_replace(' ', '', $status));
    return "<span class=\"s-tags $cls\">" . htmlspecialchars($status) . "</span>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Task Management — ProcrastiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/student.css">
<link rel="stylesheet" href="assets/css/task-celebration.css?v=1">
<style>
.task-row-overdue { background: #fef2f2 !important; }
.task-row-due-soon { background: #fffbeb !important; }
.task-row-completed { opacity: 0.65; }
.task-title-completed { text-decoration: line-through; color: #94a3b8 !important; }

body.tasks-page .task-filters{display:grid;grid-template-columns:minmax(220px,1fr) auto;align-items:center;gap:12px}
body.tasks-page .task-search{width:100%;min-width:0;box-sizing:border-box;min-height:46px}
body.tasks-page .task-search input{flex:1;width:100%;min-width:0;min-height:42px}
body.tasks-page .task-search button{flex:0 0 auto;margin-left:auto}
body.tasks-page .task-search .app-icon{flex-shrink:0}
body.tasks-page .task-filters select{height:46px}
.task-live-status{font-size:.82rem;color:#65766b;margin:0 0 12px}.task-live-status:empty{display:none}
.task-table-scroll[aria-busy="true"]{opacity:.6;transition:opacity .15s}
@media(max-width:700px){body.tasks-page .task-filters{grid-template-columns:1fr}body.tasks-page .task-search{grid-column:1/-1}}

/* Consistent action sizing, including native forms and links. */
body.tasks-page .task-actions{align-items:flex-start;gap:10px;flex-wrap:wrap}
body.tasks-page .task-more{width:156px;max-width:100%}
body.tasks-page .task-more summary{box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-height:38px;padding:9px 12px;background:#fff;border:1px solid #dce6df;border-radius:10px;font-weight:650;color:#506b59}
body.tasks-page .task-more[open] summary{background:#edf4ee;border-color:#a8c2af}
body.tasks-page .task-more-items{display:grid;gap:4px;padding:6px;margin-top:6px;border:1px solid #e1e9e3;border-radius:12px;background:#fff;box-shadow:0 6px 18px #25412b08}
body.tasks-page .task-more-items form{display:block;width:100%;margin:0;padding:0;min-width:0}
body.tasks-page .task-more-items .task-action{box-sizing:border-box;display:flex;align-items:center;justify-content:flex-start;width:100%;height:38px;min-height:38px;margin:0;padding:0 10px;border:0;border-radius:7px;box-shadow:none;text-decoration:none;transform:none;font:600 .76rem Inter,sans-serif;gap:9px;background:transparent;color:#506b59}
body.tasks-page .task-more-items .task-action:hover{background:#edf4ee;transform:none}
body.tasks-page .task-more-items .task-action.delay{color:#9b752b}
body.tasks-page .task-more-items .task-action.delete{color:#b65549}
body.tasks-page .task-more-items .task-action.delete:hover{background:#fcefeb}
body.tasks-page .task-more summary:focus-visible{outline:3px solid #a8c2af;outline-offset:2px}
body.tasks-page.dark-mode .task-more summary,body.tasks-page.dark-mode .task-more-items{background:#213128;border-color:#425b49;color:#dbe8df}
body.tasks-page.dark-mode .task-more-items .task-action{color:#c1d7c7}
body.tasks-page.dark-mode .task-more-items .task-action:hover{background:#324b3a}

</style>
</head>
<body class="student-body tasks-page">
<?php include 'includes/sidebar.php'; ?>
<style>
.tasks-page .task-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin:0 0 24px;background:#fff;border:1px solid #e5ebe6;border-radius:20px;padding:22px 0}.tasks-page .task-overview>div{padding:0 24px;border-right:1px solid #e5ebe6}.tasks-page .task-overview>div:last-child{border:0}.task-overview dt{font-size:.83rem;font-weight:600;color:#738078}.task-overview dd{margin:6px 0;font-size:1.9rem;font-weight:750;color:#355942}.task-overview small{color:#79847d;font-size:.8rem}
.tasks-page .task-list-panel{box-shadow:none;border:1px solid #e5ebe6}.tasks-page .task-list-header{display:flex;flex-direction:column;align-items:stretch;gap:16px}.tasks-page .task-filters{justify-content:flex-start;width:100%}.tasks-page .task-search{flex:1}
.task-categories{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 20px;padding-bottom:16px;border-bottom:1px solid #e8ede9}.task-categories a{display:flex;align-items:center;gap:9px;padding:9px 14px;border-radius:10px;color:#66756c;font-size:.85rem;font-weight:600;text-decoration:none}.task-categories a[aria-current]{background:#eaf2ec;color:#355d44}.task-categories span{font-size:.74rem;border-radius:6px;background:#f2f5f2;padding:2px 7px}.task-categories a:hover{background:#f1f5f1}
.tasks-page .task-title-link{font-weight:650;color:#2d4335;text-decoration:none}.task-title-link:hover{text-decoration:underline}.tasks-page .task-actions{align-items:flex-start}.task-more summary{cursor:pointer;padding:7px 10px;color:#64736a;font-size:.78rem;border:1px solid #e0e7e1;border-radius:8px;list-style:none}.task-more summary::-webkit-details-marker{display:none}.task-more-items{display:grid;gap:6px;padding-top:8px}.tasks-page .task-more-items .task-action{width:100%;justify-content:flex-start}.tasks-page .task-more-items form{display:block}
.tasks-page .task-insights{grid-column:1/-1;border:1px solid #e3eae4;border-radius:16px;background:#fff;padding:18px 22px}.task-insights>summary{cursor:pointer;font-weight:600;color:#476450}.task-insights>summary span{font-weight:400;font-size:.8rem;color:#7a867e;margin-left:10px}.tasks-page .task-insights aside{display:grid!important;grid-template-columns:1fr;margin-top:20px}.task-filter-note{font-size:.8rem;color:#6c7a71}.task-filter-note a{margin-left:12px;color:#45664f}
body.dark-mode.tasks-page .task-overview,body.dark-mode.tasks-page .task-insights{background:#202925;border-color:#3d4a42}body.dark-mode.tasks-page .task-overview dd,body.dark-mode.tasks-page .task-title-link{color:#d1e3d6}body.dark-mode .task-categories a{color:#c0cfc5}body.dark-mode .task-categories a[aria-current],body.dark-mode .task-categories span{background:#34483a;color:#e1eee5}
@media(max-width:700px){.tasks-page .task-overview>div{padding:0 12px}.task-overview dd{font-size:1.5rem}.task-overview small{font-size:.72rem}.task-categories{gap:4px}.task-categories a{padding:8px 9px;font-size:.78rem}.tasks-page .task-insights aside{grid-template-columns:1fr}.task-insights>summary span{display:block;margin:8px 0 0}}
</style>

<style>
/* Refined task dashboard surfaces */
.tasks-page .task-overview{grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0 0 22px;padding:0;border:0;background:transparent}
.tasks-page .task-overview>div{position:relative;isolation:isolate;overflow:hidden;min-height:122px;padding:19px 21px;border:1px solid #e3ebe5;border-right:1px solid #e3ebe5;border-radius:18px;background:linear-gradient(145deg,#fff 0%,#fbfdfb 100%);box-shadow:0 8px 24px rgba(36,58,42,.035);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.tasks-page .task-overview>div:last-child{border-right:1px solid #e3ebe5}
.tasks-page .task-overview>div:before{position:absolute;z-index:-1;top:-35px;right:-25px;width:112px;height:112px;border-radius:50%;background:var(--metric-glow,#edf5ef);content:"";opacity:.75}
.tasks-page .task-overview>div:nth-child(1){--metric-glow:#e8f3eb;--metric-color:#426d50}
.tasks-page .task-overview>div:nth-child(2){--metric-glow:#e9f1ee;--metric-color:#3d6d59}
.tasks-page .task-overview>div:nth-child(3){--metric-glow:#fbf0e5;--metric-color:#ad7040}
.tasks-page .task-overview>div:hover{transform:translateY(-2px);border-color:#d1e1d5;box-shadow:0 13px 30px rgba(36,58,42,.075)}
.tasks-page .task-overview dt{color:#718178;font-size:.75rem;font-weight:700;letter-spacing:.035em}
.tasks-page .task-overview dd{margin:9px 0 2px;color:var(--metric-color);font-size:2.15rem;font-weight:800;letter-spacing:-.055em;line-height:1}
.tasks-page .task-overview small{color:#849087;font-size:.76rem}
.tasks-page .task-list-panel{border-color:#e2eae4;border-radius:20px;box-shadow:0 10px 34px rgba(35,55,41,.045)}
.tasks-page .task-list-header h3{font-size:1.2rem;letter-spacing:-.035em}
.tasks-page .task-list-header p{color:#7c8981}
.tasks-page .task-filters select,.tasks-page .task-search{min-height:44px;border-color:#dfe8e1;border-radius:12px;background:#fbfdfb;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
.tasks-page .task-filters select:hover,.tasks-page .task-search:hover{border-color:#c5d7c9;background:#fff}
.tasks-page .task-search:focus-within{border-color:#83a88c;box-shadow:0 0 0 4px rgba(110,151,121,.12)}
.tasks-page .task-search button{min-height:32px;padding-inline:13px;border-radius:9px;box-shadow:0 3px 8px rgba(60,98,68,.12)}
.tasks-page .task-categories{gap:7px;margin-top:2px;padding-bottom:17px}
.tasks-page .task-categories a{min-height:39px;padding:8px 13px;border:1px solid transparent;border-radius:11px;transition:background .18s ease,color .18s ease,border-color .18s ease}
.tasks-page .task-categories a[aria-current]{border-color:#dce9df;background:#edf5ef;color:#3e694b}
.tasks-page .task-categories span{min-width:22px;padding:3px 7px;border-radius:7px;background:#fff;color:#6c7d70;text-align:center}
.tasks-page .task-table-scroll{overflow:visible;border-color:#e4ebe5;border-radius:15px}
.tasks-page .task-management-table{min-width:1220px;table-layout:fixed}
.tasks-page .task-management-table .task-col-title{width:22%}
.tasks-page .task-management-table .task-col-deadline{width:20%}
.tasks-page .task-management-table .task-col-priority{width:11%}
.tasks-page .task-management-table .task-col-status{width:10%}
.tasks-page .task-management-table .task-col-actions{width:22%}
.tasks-page .task-management-table thead th{height:44px;background:#f4f8f5!important;color:#75877a!important}
.tasks-page .task-management-table tbody td{height:67px;border-bottom-color:#edf1ee!important}
.tasks-page .task-management-table tbody tr:last-child td{border-bottom:0!important}
.tasks-page .task-management-table tbody tr:hover td{background:#f8fbf8!important}
.tasks-page .task-title-link{display:inline-flex;align-items:center;min-height:34px;color:#2d4937}
.tasks-page .task-actions{position:relative;align-items:center;gap:9px}
.tasks-page .task-action.complete{min-height:36px;padding-inline:12px;border:1px solid #e2eee4;border-radius:10px;font-size:.74rem}
body.student-body.tasks-page .task-actions-visible{display:flex!important;align-items:center!important;justify-content:flex-start!important;flex-wrap:nowrap!important;gap:7px!important;min-width:0!important}
body.student-body.tasks-page .task-actions-visible form{display:flex!important;flex:0 0 auto!important;align-items:center!important;margin:0!important;padding:0!important}
body.student-body.tasks-page .task-management-table tbody td:last-child .task-actions-visible .task-ux-button{box-sizing:border-box!important;display:inline-flex!important;flex:0 0 auto!important;align-items:center!important;justify-content:center!important;gap:6px!important;height:40px!important;min-height:40px!important;margin:0!important;padding:0 11px!important;border:1px solid transparent!important;border-radius:11px!important;font:650 .75rem/1 Inter,sans-serif!important;white-space:nowrap!important;text-decoration:none!important;box-shadow:0 2px 6px rgba(35,55,41,.045)!important;cursor:pointer!important;transition:background .16s ease,border-color .16s ease,transform .16s ease,box-shadow .16s ease!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button .app-icon{width:15px!important;height:15px!important;flex:0 0 15px!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button.complete{width:101px!important;border-color:#5f8369!important;background:#668c71!important;color:#fff!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button.complete:hover{transform:translateY(-1px)!important;border-color:#50765b!important;background:#557e61!important;box-shadow:0 5px 12px rgba(70,111,80,.18)!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button.edit{width:72px!important;border-color:#dfe8e1!important;background:#fff!important;color:#4f6957!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button.edit:hover{transform:translateY(-1px)!important;border-color:#bfd2c3!important;background:#f1f7f2!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button.delete{width:82px!important;border-color:#f0ddda!important;background:#fffafa!important;color:#a4564c!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button.delete:hover{transform:translateY(-1px)!important;border-color:#e6c1bb!important;background:#fdf0ed!important;box-shadow:0 5px 12px rgba(164,86,76,.09)!important}
body.student-body.tasks-page .task-actions-visible .task-ux-button:focus-visible{outline:3px solid rgba(109,143,122,.28)!important;outline-offset:2px!important}
body.student-body.tasks-page .task-management-table tbody td:last-child{min-width:0!important;padding-right:18px!important}
.tasks-page .task-more{position:relative;width:158px;z-index:2}
.tasks-page .task-more[open]{z-index:30}
.tasks-page .task-more summary{min-height:40px;padding:0 12px;border-color:#dbe6dd;border-radius:11px;background:#fff;color:#4d6c56;font-size:.78rem;transition:background .18s ease,border-color .18s ease}
.tasks-page .task-more summary:hover,.tasks-page .task-more[open] summary{border-color:#b9d0be;background:#f1f7f2}
.tasks-page .task-more-items{position:absolute;top:calc(100% + 7px);left:0;z-index:50;width:100%;box-sizing:border-box;gap:5px;margin:0;padding:7px;border-color:#e1e9e3;border-radius:13px;box-shadow:0 14px 34px rgba(27,48,34,.14);animation:task-menu-in .16s ease-out}
.tasks-page .task-more-items .task-action{height:39px;border-radius:9px;font-size:.77rem}
.tasks-page .task-more-items .task-action .app-icon{width:15px;height:15px}
.tasks-page .task-insights{border-color:#e2eae4;border-radius:18px;box-shadow:0 8px 24px rgba(35,55,41,.035)}
.tasks-page .task-insights>summary{padding:3px 0;color:#42674b;font-size:.92rem}
.tasks-page .task-insights aside>.s-panel{border-color:#e8eee9;border-radius:16px}
.tasks-page .task-insights .progress-ring{position:relative;isolation:isolate;width:132px!important;height:132px!important;border:0!important;background:conic-gradient(#70977b 0 var(--completion),#eaf3ec var(--completion) 100%);box-shadow:0 7px 20px rgba(65,105,74,.08)}
.tasks-page .task-insights .progress-ring:before{position:absolute;z-index:-1;inset:10px;border:1px solid #edf2ee;border-radius:50%;background:#fff;content:""}
.tasks-page .task-insights .progress-ring span{color:#365e42!important;font-size:1.9rem!important;letter-spacing:-.04em}
@keyframes task-menu-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
body.dark-mode.tasks-page .task-overview>div{border-color:#3a4940;background:linear-gradient(145deg,#202925,#222e27);box-shadow:none}
body.dark-mode.tasks-page .task-overview>div:last-child{border-color:#3a4940}
body.dark-mode.tasks-page .task-overview dt,body.dark-mode.tasks-page .task-overview small{color:#acbbb0}
body.dark-mode.tasks-page .task-list-panel,body.dark-mode.tasks-page .task-insights{border-color:#3a4940;background:#202925}
body.dark-mode.tasks-page .task-filters select,body.dark-mode.tasks-page .task-search,body.dark-mode.tasks-page .task-more summary{background:#26312b;border-color:#3d4a42;color:#e0e9e2}
body.dark-mode.tasks-page .task-categories a[aria-current]{border-color:#405b47;background:#304536;color:#d9e9dd}
body.dark-mode.tasks-page .task-categories span{background:#26312b;color:#cad8ce}
body.dark-mode.tasks-page .task-more-items{background:#202925;border-color:#425b49}
body.dark-mode.tasks-page .task-insights .progress-ring:before{border-color:#34423a;background:#202925}
body.dark-mode.student-body.tasks-page .task-actions-visible .task-ux-button.edit{border-color:#405247!important;background:#26312b!important;color:#d4e2d7!important}
body.dark-mode.student-body.tasks-page .task-actions-visible .task-ux-button.edit:hover{border-color:#58735f!important;background:#304238!important}
body.dark-mode.student-body.tasks-page .task-actions-visible .task-ux-button.delete{border-color:#59403c!important;background:#302724!important;color:#e2aaa1!important}
body.dark-mode.student-body.tasks-page .task-actions-visible .task-ux-button.delete:hover{background:#3b2c29!important}
@media(max-width:1050px){.tasks-page .task-table-scroll{overflow-x:auto;overflow-y:visible}.tasks-page .task-more-items{position:static;width:auto;margin-top:7px;box-shadow:0 6px 18px #25412b08;animation:none}}
 @media(max-width:760px){
 .tasks-page .task-overview{gap:9px}.tasks-page .task-overview>div{min-height:105px;padding:15px 13px;border-radius:15px}.tasks-page .task-overview dd{font-size:1.75rem}
 .tasks-page .task-table-scroll{overflow:visible}.tasks-page .task-management-table colgroup{display:none}.tasks-page .task-management-table{min-width:0;table-layout:auto}
 .tasks-page .task-management-table tbody td{height:auto!important}
 .tasks-page .task-actions{align-items:flex-start}.tasks-page .task-actions-visible{min-width:0;flex-wrap:wrap;gap:7px}
 body.student-body.tasks-page .task-management-table tbody td:last-child{display:block!important;min-width:0!important;height:auto!important;min-height:0!important;padding:12px 14px!important}
 body.student-body.tasks-page .task-management-table tbody td:last-child::before{display:block;margin-bottom:8px}
 body.student-body.tasks-page .task-management-table tbody td:last-child .task-actions-visible{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;align-items:stretch!important;justify-content:stretch!important;flex-wrap:initial!important;gap:8px!important;width:100%!important}
 body.student-body.tasks-page .task-management-table tbody td:last-child .task-actions-visible form{display:flex!important;width:100%!important;min-width:0!important;flex:initial!important}
 body.student-body.tasks-page .task-management-table tbody td:last-child .task-actions-visible .task-ux-button{box-sizing:border-box!important;display:inline-flex!important;width:100%!important;min-width:0!important;height:44px!important;min-height:44px!important;padding:0 8px!important;white-space:nowrap!important}
 body.student-body.tasks-page .task-management-table tbody td:last-child .task-actions-visible .task-complete-form{grid-column:1/-1!important}
 }
@media(max-width:430px){.tasks-page .task-overview{grid-template-columns:1fr}.tasks-page .task-overview>div{min-height:90px}.tasks-page .task-overview dd{margin-top:6px}}
@media(prefers-reduced-motion:reduce){.tasks-page .task-overview>div,.tasks-page .task-categories a,.tasks-page .task-more-items{animation:none;transition:none}}
.task-confirm-dialog[open]{position:fixed!important;inset:50% auto auto 50%!important;margin:0!important;transform:translate(-50%,-50%)!important;max-height:calc(100dvh - 32px);}
.task-confirm-dialog{width:min(480px,calc(100vw - 32px));box-sizing:border-box;padding:0;overflow:auto;border:1px solid #e0e9e2;border-radius:25px;background:radial-gradient(ellipse at 50% -25%,#eff7f0 0,transparent 52%),#fff;color:#28392e;box-shadow:0 30px 100px rgba(20,38,26,.3);animation:task-confirm-in .18s ease-out}
.task-confirm-dialog:before{position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#729781,#c7dec9 50%,#729781);content:""}
.task-confirm-dialog::backdrop{background:rgba(24,35,29,.5);backdrop-filter:blur(6px)}
.task-confirm-content{padding:36px 38px 32px;text-align:center}
.task-confirm-mark{display:grid;width:58px;height:58px;margin:0 auto 18px;place-items:center;border:1px solid #dce9de;border-radius:19px;background:linear-gradient(145deg,#f4faf4,#eaf4ec);box-shadow:0 8px 22px rgba(64,107,75,.1);color:#668c71}
.task-confirm-mark .app-icon{width:22px;height:22px}
.task-confirm-eyebrow{margin:0 0 8px;color:#6d8f77;font-size:.68rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
.task-confirm-dialog h2{margin:0;color:#28392e;font-size:1.65rem;letter-spacing:-.045em;line-height:1.2}
.task-confirm-message{max-width:370px;margin:12px auto 26px;color:#6b7a70;font-size:.94rem;line-height:1.65;overflow-wrap:anywhere}
.task-confirm-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
.task-confirm-actions button{min-width:148px;min-height:46px;padding:0 16px;border:1px solid #dfe8e1;border-radius:12px;background:#fff;color:#53685a;font:650 .82rem Inter,sans-serif;cursor:pointer;transition:background .16s ease,border-color .16s ease,transform .16s ease,box-shadow .16s ease}
.task-confirm-actions button:hover{transform:translateY(-1px);background:#f4f8f4;border-color:#c9d9cc}
.task-confirm-actions .task-confirm-yes{border-color:#5f8369;background:linear-gradient(135deg,#71947e,#62856f);color:#fff;box-shadow:0 7px 16px rgba(70,111,80,.2)}
.task-confirm-actions .task-confirm-yes:hover{border-color:#50765b;background:#557e61}
.task-confirm-actions button:focus-visible{outline:3px solid rgba(109,143,122,.3);outline-offset:2px}
@keyframes task-confirm-in{from{opacity:0}to{opacity:1}}
@media(max-width:520px){.task-confirm-content{padding:30px 22px 24px}.task-confirm-dialog h2{font-size:1.42rem}.task-confirm-actions{display:grid;grid-template-columns:1fr}.task-confirm-actions button{width:100%}}
@media(prefers-reduced-motion:reduce){.task-confirm-dialog{animation:none}}
</style>

<main class="s-main">
<div class="s-inner">

<header class="s-head">
<div>
<div class="s-label">Tasks</div>
<h1>Task Management</h1>
<p>Create, track, and prioritize your assignments in one place.</p>
</div>
<div class="s-head-actions">
<a href="log_delay.php" class="s-btn">Log delay</a>
<button class="s-btn primary" onclick="location.href='add_task.php'">&#43; Add Task</button>
</div>
</header>

<?php if(isset($_GET['added']) && $_GET['added'] === '1'): ?>
<div id="taskCreatedNotice">
<?php $aiPriorityNotice = $_SESSION['task_ai_priority'] ?? null; unset($_SESSION['task_ai_priority']); ?>
<div class="s-panel" role="status" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">Task created successfully.<?php if (is_array($aiPriorityNotice)): ?> AI assigned <strong><?php echo htmlspecialchars($aiPriorityNotice['priority'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($aiPriorityNotice['reason'])): ?> — <?php echo htmlspecialchars($aiPriorityNotice['reason'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>. Your new task is in the list below.<?php else: ?> Your new task is in the list below.<?php endif; ?></div>
<?php
$emailStatus=$_SESSION['task_email_status']??'';unset($_SESSION['task_email_status']);
$emailMessages=['sent'=>'A confirmation email was accepted by the mail server. Check your inbox or spam folder.','disabled'=>'Email notifications are off. You can enable them in Settings.','unconfigured'=>'Your task is saved. Email delivery is not set up yet.','failed'=>'Your task is saved, but the confirmation email could not be sent.'];
if(isset($emailMessages[$emailStatus])): ?><p role="status" class="s-card-meta"><?php echo htmlspecialchars($emailMessages[$emailStatus]); ?></p><?php endif; ?>
<script>
window.setTimeout(function () {
    var notice = document.getElementById('taskCreatedNotice');
    if (!notice) return;
    notice.style.transition = 'opacity 240ms ease, transform 240ms ease';
    notice.style.opacity = '0';
    notice.style.transform = 'translateY(-6px)';
    window.setTimeout(function () { notice.remove(); }, 260);
}, 5000);
</script>
</div>
<?php endif; ?>

<details class="priority-guide"><summary>How priorities work <span>Assigned by AI</span></summary><div><p><b>High</b> AI considers the task especially important or urgent.</p><p><b>Medium</b> AI considers it a balanced priority.</p><p><b>Low</b> AI considers it less urgent based on the details provided.</p></div><small>Priority is assigned from each task's title, details, and deadline. Editing a task asks AI to reassess it. You cannot manually override the AI decision.</small></details>
<style>.priority-guide{margin:0 0 20px;padding:16px 18px;background:#f1f6f2;border-radius:12px;color:#4d6b56}.priority-guide summary{cursor:pointer;font-size:.85rem;font-weight:600}.priority-guide summary span{font-size:.74rem;font-weight:400;margin-left:12px;color:#78897d}.priority-guide>div{display:flex;gap:20px;flex-wrap:wrap;margin:16px 0 10px}.priority-guide p{font-size:.8rem}.priority-guide b{display:block;margin-bottom:5px}.priority-guide small{font-size:.74rem}.dark-mode .priority-guide{background:#293c2f;color:#d4e5d9}@media(max-width:600px){.priority-guide summary span{display:block;margin:5px 0 0}}</style>
<dl class="task-overview">
<div><dt>All tasks</dt><dd><?php echo $total_tasks; ?></dd><small><?php echo $pending_tasks + $inprogress_tasks; ?> active</small></div>
<div><dt>Completed</dt><dd><?php echo $completed_tasks; ?></dd><small><?php echo $completion_rate; ?>% complete</small></div>
<div><dt>Overdue</dt><dd><?php echo $overdue; ?></dd><small><?php echo $overdue ? 'Needs attention' : 'All clear'; ?></small></div>
</dl>
<section class="s-two">
<div class="s-panel task-list-panel" id="task-list">
    <div class="s-panel-header task-list-header">
        <div><div class="s-label">Task list</div><h3>Manage your assignments</h3><p>Use the filters to find a task quickly.</p></div>
        <form class="task-filters" method="get" action="tasks.php">
            <label class="task-search"><i class="app-icon" data-lucide="search" aria-hidden="true"></i><input type="search" aria-label="Search tasks" name="search" placeholder="Search tasks" value="<?php echo htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><button type="submit">Search</button></label>
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($_GET['status'] ?? 'all', ENT_QUOTES); ?>">
            <select name="priority" aria-label="Filter by priority">
                <option value="all" <?php echo $priorityFilter === '' ? 'selected' : ''; ?>>All priority</option>
                <option value="High" <?php echo $priorityFilter === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Medium" <?php echo $priorityFilter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="Low" <?php echo $priorityFilter === 'Low' ? 'selected' : ''; ?>>Low</option>
            </select>
        </form>
    </div>
    <p class="task-live-status" role="status" aria-live="polite"></p>
    <nav class="task-categories" aria-label="Task status categories">
    <?php foreach (['all' => ['All tasks', $pending_tasks + $inprogress_tasks], 'Pending' => ['Pending', $pending_tasks], 'In Progress' => ['In progress', $inprogress_tasks]] as $filter => [$label, $count]):
        $params = array_intersect_key($_GET, array_flip(['search', 'priority']));
        $params['status'] = $filter;
        $activeTab = ($statusFilter === '' && $filter === 'all') || $statusFilter === $filter;
    ?>
    <a href="tasks.php?<?php echo htmlspecialchars(http_build_query($params), ENT_QUOTES); ?>" <?php echo $activeTab ? 'aria-current="page"' : ''; ?>><?php echo $label; ?><span><?php echo $count; ?></span></a>
    <?php endforeach; ?>
    </nav>
    <?php if ($search !== '' || $priorityFilter !== ''): ?><p class="task-filter-note"><?php echo mysqli_num_rows($result); ?> matching tasks <a href="tasks.php?<?php echo htmlspecialchars(http_build_query(['status' => $_GET['status'] ?? 'all']), ENT_QUOTES); ?>">Clear filters</a></p><?php endif; ?>
    <div class="table-scroll task-table-scroll">
        <table class="task-management-table">
            <colgroup><col class="task-col-title"><col class="task-col-deadline"><col class="task-col-priority"><col class="task-col-status"><col class="task-col-actions"></colgroup>
            <thead><tr>
                <th scope="col">Task</th><th scope="col">Deadline</th>
                <th scope="col">Priority</th><th scope="col">Status</th><th scope="col">Actions</th>
            </tr></thead>
            <tbody>
            <?php while($row = mysqli_fetch_assoc($result)):
                $overdueClass = getOverdueClass($row['deadline'], $row['status'], $row['deadline_time']);
                $rowClass = $overdueClass === 'overdue' ? 'task-row-overdue' : ($overdueClass === 'due-soon' ? 'task-row-due-soon' : ($row['status'] === 'Completed' ? 'task-row-completed' : ''));
                $titleClass = $row['status'] === 'Completed' ? 'task-title-completed' : '';
            ?>
                <tr id="task-<?php echo (int)$row['id']; ?>" class="<?php echo $rowClass; ?>">
                    <td data-label="Task"><a class="task-title-link <?php echo $titleClass; ?>" href="view_task.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars($row['title']); ?></a></td>
                    <td data-label="Deadline" class="task-deadline-cell">
                        <?php if($overdueClass === 'overdue'): ?><span class="task-deadline overdue"><i class="app-icon" data-lucide="triangle-alert" aria-hidden="true"></i>Overdue</span>
                        <?php elseif($overdueClass === 'due-soon'): ?><span class="task-deadline soon"><i class="app-icon" data-lucide="clock-3" aria-hidden="true"></i>Due soon</span>
                        <?php else: ?><span class="task-deadline"><i class="app-icon" data-lucide="calendar-days" aria-hidden="true"></i><?php endif; ?>
                        <time datetime="<?php echo htmlspecialchars($row['deadline'] ? $row['deadline'] . (!empty($row['deadline_time']) ? 'T' . substr($row['deadline_time'], 0, 5) : '') : '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(app_task_deadline_display($row['deadline'], $row['deadline_time']), ENT_QUOTES, 'UTF-8'); ?></time>
                        <?php if($overdueClass !== 'overdue' && $overdueClass !== 'due-soon'): ?></span><?php endif; ?>
                    </td>
                    <td data-label="Priority"><?php echo getPriorityBadge($row['displayed_priority']); ?></td>
                    <td data-label="Status"><?php echo getStatusBadge($row['status']); ?></td>
                    <td data-label="Actions"><div class="task-actions task-actions-visible">
                        <?php if($row['status'] !== 'Completed'): ?>
                            <form method="post" action="complete_task.php" class="task-complete-form" data-task-title="<?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><?php app_csrf_field(); ?><button type="submit" class="task-action task-ux-button complete"><i class="app-icon" data-lucide="circle-check" aria-hidden="true"></i><span>Complete</span></button></form>
                        <?php endif; ?>
                        <a class="task-action task-ux-button edit" href="edit_task.php?id=<?php echo (int)$row['id']; ?>"><i class="app-icon" data-lucide="pencil" aria-hidden="true"></i><span>Edit</span></a>
                        <form method="post" action="delete_task.php" onsubmit="return confirm('Delete this task?')"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><?php app_csrf_field(); ?><button type="submit" class="task-action task-ux-button delete"><i class="app-icon" data-lucide="trash-2" aria-hidden="true"></i><span>Delete</span></button></form>
                    </div></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php if(mysqli_num_rows($result) == 0): ?><div class="s-empty task-empty">No tasks match these filters. Try another search or add a task.</div><?php endif; ?>
    </div>
</div>
</section>

</div>
</main>

<dialog class="task-confirm-dialog" id="completeTaskConfirm" aria-labelledby="completeTaskConfirmTitle" aria-describedby="completeTaskConfirmMessage">
    <div class="task-confirm-content">
        <div class="task-confirm-mark" aria-hidden="true"><i class="app-icon" data-lucide="circle-check"></i></div>
        <p class="task-confirm-eyebrow">One last check</p>
        <h2 id="completeTaskConfirmTitle">Really complete this task?</h2>
        <p class="task-confirm-message" id="completeTaskConfirmMessage">This task will move to your completed history.</p>
        <div class="task-confirm-actions">
            <button type="button" data-cancel-completion>No, keep task</button>
            <button type="button" class="task-confirm-yes" data-confirm-completion>Yes, complete</button>
        </div>
    </div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('completeTaskConfirm');
    if (!dialog) return;
    const message = document.getElementById('completeTaskConfirmMessage');
    const cancelButton = dialog.querySelector('[data-cancel-completion]');
    const confirmButton = dialog.querySelector('[data-confirm-completion]');
    let pendingForm = null;

    document.addEventListener('submit', event => {
        const form = event.target.closest('form.task-complete-form');
        if (!form) return;
        if (form.dataset.confirmed === 'yes') {
            delete form.dataset.confirmed;
            return;
        }
        event.preventDefault();
        pendingForm = form;
        const title = form.dataset.taskTitle || 'this task';
        message.textContent = `Mark “${title}” as completed? You can find it later in your completed history.`;
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            cancelButton.focus();
        } else if (window.confirm(`Really complete “${title}”?`)) {
            form.dataset.confirmed = 'yes';
            form.requestSubmit();
        }
    });

    cancelButton.addEventListener('click', () => dialog.close());
    confirmButton.addEventListener('click', () => {
        if (!pendingForm) return dialog.close();
        const form = pendingForm;
        pendingForm = null;
        dialog.close();
        form.dataset.confirmed = 'yes';
        form.requestSubmit();
    });
    dialog.addEventListener('close', () => { pendingForm = null; });
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
})();
</script>

<?php if (is_array($completionCelebration)): ?>
<dialog class="task-celebration" id="taskCelebration" aria-labelledby="taskCelebrationTitle" aria-describedby="taskCelebrationMessage">
    <div class="celebration-sparkles" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    <form method="dialog" class="celebration-close-form"><button class="celebration-close" aria-label="Close celebration">&times;</button></form>
    <div class="celebration-icon" aria-hidden="true"><span>&#10003;</span></div>
    <p class="celebration-eyebrow">A WIN WORTH CELEBRATING</p>
    <h2 id="taskCelebrationTitle">Well done!</h2>
    <p id="taskCelebrationMessage" class="celebration-message">You completed <strong><?php echo htmlspecialchars((string)($completionCelebration['title'] ?? 'your task'), ENT_QUOTES, 'UTF-8'); ?></strong>. That's one meaningful step forward.</p>
    <div class="celebration-saved"><span aria-hidden="true">&#10003;</span> Progress saved</div>
    <form method="dialog" class="celebration-continue-form"><button class="celebration-continue">Back to my tasks <span aria-hidden="true">&rarr;</span></button></form>
</dialog>
<script>
(() => {
 const dialog=document.getElementById('taskCelebration');
 if(!dialog)return;
 if(typeof dialog.showModal==='function')dialog.showModal();
 else dialog.setAttribute('open','');
 dialog.querySelector('.celebration-close')?.focus();
 dialog.addEventListener('click',event=>{if(event.target===dialog&&typeof dialog.close==='function')dialog.close();});
})();
</script>
<?php endif; ?>


<script>
(() => {
 const panel=document.querySelector('.task-list-panel');
 const form=panel.querySelector('.task-filters');
 const status=panel.querySelector('.task-live-status');
 let controller=null,sequence=0,timer;
 function formUrl(){const url=new URL(form.action);url.search=new URLSearchParams(new FormData(form)).toString();return url;}
 function syncForm(url){for(const name of ['search','status','priority'])form.elements[name].value=url.searchParams.get(name)||(name==='search'?'':'all');}
 async function update(url,historyMode='push'){
  clearTimeout(timer);const current=++sequence;if(controller)controller.abort();controller=new AbortController();
  const timeout=setTimeout(()=>controller.abort(),15000);
  status.textContent='Updating tasks...';panel.querySelector('.task-table-scroll').setAttribute('aria-busy','true');
  try{
   const response=await fetch(url,{signal:controller.signal,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
   if(!response.ok)throw new Error('request');
   const doc=new DOMParser().parseFromString(await response.text(),'text/html');
   if(current!==sequence)return;
   const next=doc.querySelector('.task-list-panel');
   if(!next)throw new Error('session');
   for(const selector of ['.task-categories','.task-table-scroll']){
    const replacement=next.querySelector(selector);if(!replacement)throw new Error('content');
    panel.querySelector(selector).replaceWith(replacement);
   }
   panel.querySelector('.task-filter-note')?.remove();
   const note=next.querySelector('.task-filter-note');if(note)panel.querySelector('.task-categories').after(note);
   if(historyMode==='push'&&url.href!==location.href)history.pushState({},'',url);
   if(historyMode==='replace')history.replaceState({},'',url);
   if(window.lucide)window.lucide.createIcons();
   const count=panel.querySelectorAll('tbody tr').length;
   status.textContent=count+' '+(count===1?'task':'tasks')+' shown.';
  }catch(error){
   if(current!==sequence)return;
   status.textContent='Could not update the list. Check your connection or sign in again, then press Search to retry.';
  }finally{
   clearTimeout(timeout);if(current===sequence)panel.querySelector('.task-table-scroll').setAttribute('aria-busy','false');
  }
 }
 form.addEventListener('submit',event=>{event.preventDefault();update(formUrl());});
 form.querySelectorAll('select').forEach(select=>select.addEventListener('change',()=>update(formUrl())));
 form.elements.search.addEventListener('input',()=>{
  clearTimeout(timer);++sequence;if(controller)controller.abort();
  timer=setTimeout(()=>update(formUrl(),'replace'),250);
 });
 panel.addEventListener('click',event=>{
  const link=event.target.closest('.task-categories a, .task-filter-note a');
  if(!link||link.hasAttribute('data-profile-history')||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button!==0)return;
  event.preventDefault();const url=new URL(link.href);
  if(link.closest('.task-categories')){form.elements.status.value=url.searchParams.get('status')||'all';update(formUrl());}
  else{syncForm(url);update(url);}
 });
 window.addEventListener('popstate',()=>{const url=new URL(location.href);syncForm(url);update(url,'none');});
})();
</script>

</body>
</html>
