<?php
// Legacy URL: system-wide reporting belongs to the role-protected admin workspace.
// Do not query or render student records from this route.
header('Cache-Control: no-store');
header('Location: admin/reports.php', true, 302);
exit();
