<?php $insightsCurrentPage = basename($_SERVER['PHP_SELF']); ?>
<nav class="insights-tabs" aria-label="Insights sections">
    <a href="reports.php" class="<?php echo $insightsCurrentPage === 'reports.php' ? 'active' : ''; ?>" <?php echo $insightsCurrentPage === 'reports.php' ? 'aria-current="page"' : ''; ?>>Weekly report</a>
    <a href="patterns.php" class="<?php echo $insightsCurrentPage === 'patterns.php' ? 'active' : ''; ?>" <?php echo $insightsCurrentPage === 'patterns.php' ? 'aria-current="page"' : ''; ?>>Pattern analysis</a>
    <a href="tendency.php" class="<?php echo $insightsCurrentPage === 'tendency.php' ? 'active' : ''; ?>" <?php echo $insightsCurrentPage === 'tendency.php' ? 'aria-current="page"' : ''; ?>>Tendency</a>
</nav>
