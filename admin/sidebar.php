<?php
$current = basename($_SERVER['PHP_SELF']);
function sideLink($href, $icon, $label, $current) {
    $file   = basename($href);
    $active = ($file === $current) ? ' class="active"' : '';
    echo "<a href=\"$href\"$active><span class=\"s-icon\">$icon</span> $label</a>\n";
}
?>
<div class="sidebar">
  <div class="sidebar-brand">⚙️ Admin Panel<small>Adam Indoors</small></div>
  <div class="sidebar-label">Main Menu</div>
  <?php
  sideLink('admin-dashboard.php',      '📊', 'Dashboard',          $current);
  sideLink('admin-bookings.php',       '📋', 'Manage Bookings',     $current);
  sideLink('admin-courts.php',         '🏸', 'Manage Courts',       $current);
  sideLink('admin-packages.php',       '📦', 'Manage Packages',     $current);
  sideLink('admin-coaches.php',        '🎽', 'Manage Coaches',      $current);
  sideLink('admin-coach-bookings.php', '📝', 'Coach Bookings',      $current);
  sideLink('admin-timeslots.php',      '🕐', 'Time Slots',          $current);
  sideLink('admin-availability.php',   '📅', 'Manage Availability', $current);
  sideLink('admin-payments.php',       '💳', 'Payments',            $current);
  sideLink('admin-reports.php',        '📊', 'Reports',             $current);
  sideLink('admin-users.php',          '👥', 'Manage Users',        $current);
  ?>
  <div class="sidebar-label">Account</div>
  <a href="../logout.php"><span class="s-icon">🚪</span> Logout</a>
</div>