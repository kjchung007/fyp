<?php
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

// Get all recurring booking groups with pending items
$query = "SELECT 
          b.recurring_group_id,
          r.room_name,
          r.building,
          u.name as user_name,
          u.email,
          u.role,
          MIN(b.booking_date) as first_date,
          MAX(b.booking_date) as last_date,
          b.start_time,
          b.end_time,
          b.purpose,
          COUNT(*) as total_bookings,
          SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
          GROUP_CONCAT(CASE WHEN b.status = 'pending' THEN b.booking_id END) as pending_ids,
          GROUP_CONCAT(CASE WHEN b.status = 'pending' THEN b.booking_date END ORDER BY b.booking_date) as pending_dates
          FROM bookings b
          JOIN rooms r ON b.room_id = r.room_id
          JOIN users u ON b.user_id = u.user_id
          WHERE b.recurring_group_id IS NOT NULL
          GROUP BY b.recurring_group_id
          HAVING pending_count > 0
          ORDER BY first_date DESC";

$recurring_groups = $conn->query($query);

// Get pending single bookings count
$single_pending = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending' AND recurring_group_id IS NULL")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Booking Approval - Admin</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .recurring-group-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 5px solid #2196f3;
        }
        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        .pending-dates-list {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            max-height: 200px;
            overflow-y: auto;
        }
        .date-item {
            padding: 8px;
            margin: 5px 0;
            background: white;
            border-radius: 4px;
            border-left: 3px solid #f39c12;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .bulk-action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .stats-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
    </style>
    <script>
        function approveAllInGroup(groupId, count) {
            if (confirm('Approve all ' + count + ' pending bookings in this series?')) {
                window.location.href = '../actions/admin_bulk_approval.php?action=approve_group&group_id=' + encodeURIComponent(groupId);
            }
        }
        
        function rejectAllInGroup(groupId, count) {
            const reason = prompt('Reject all ' + count + ' bookings?\n\nReason (optional):');
            if (reason !== null) {
                window.location.href = '../actions/admin_bulk_approval.php?action=reject_group&group_id=' + encodeURIComponent(groupId) + '&reason=' + encodeURIComponent(reason);
            }
        }
        
        function approveSelected(groupId) {
            const checkboxes = document.querySelectorAll('.booking-check-' + groupId.replace(/[^a-z0-9]/gi, '') + ':checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (selectedIds.length === 0) {
                alert('Please select at least one booking to approve.');
                return;
            }
            
            if (confirm('Approve ' + selectedIds.length + ' selected booking(s)?')) {
                window.location.href = '../actions/admin_bulk_approval.php?action=approve_selected&booking_ids=' + selectedIds.join(',');
            }
        }
        
        function rejectSelected(groupId) {
            const checkboxes = document.querySelectorAll('.booking-check-' + groupId.replace(/[^a-z0-9]/gi, '') + ':checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (selectedIds.length === 0) {
                alert('Please select at least one booking to reject.');
                return;
            }
            
            const reason = prompt('Reject ' + selectedIds.length + ' booking(s)?\n\nReason (optional):');
            if (reason !== null) {
                window.location.href = '../actions/admin_bulk_approval.php?action=reject_selected&booking_ids=' + selectedIds.join(',') + '&reason=' + encodeURIComponent(reason);
            }
        }
        
        function toggleSelectAll(groupId) {
            const selectAllCheckbox = document.getElementById('select-all-' + groupId.replace(/[^a-z0-9]/gi, ''));
            const checkboxes = document.querySelectorAll('.booking-check-' + groupId.replace(/[^a-z0-9]/gi, ''));
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        }
    </script>
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>FCSIT Admin</h3>
                <p style="font-size: 0.85em; opacity: 0.8; margin-top: 5px;">
                    <?php echo htmlspecialchars($_SESSION['name']); ?>
                </p>
            </div>
            <ul class="list-unstyled components">
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="manage_bookings.php">Manage Bookings</a></li>
                <li class="active"><a href="bulk_approval.php">Bulk Approval</a></li>
                <li><a href="manage_rooms.php">Manage Rooms</a></li>
                <li><a href="manage_users.php">Manage Users</a></li>
                <li><a href="maintenance_reports.php">Maintenance Reports</a></li>
                <li><a href="system_logs.php">System Logs</a></li>
                <li><a href="calendar.php">Calendar View</a></li>
                <li><a href="../dashboard.php">User View</a></li>
                <li><a href="../actions/logout.php">Logout</a></li>
            </ul>
        </nav>
        
        <div id="content">
            <h2>📋 Bulk Booking Approval</h2>
            
            <?php
            if (isset($_SESSION['success'])) {
                echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
                unset($_SESSION['success']);
            }
            if (isset($_SESSION['error'])) {
                echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
                unset($_SESSION['error']);
            }
            ?>
            
            <div class="stats-banner">
                <h3 style="margin: 0 0 10px 0;">Pending Approval Summary</h3>
                <div style="display: flex; gap: 30px;">
                    <div>
                        <div style="font-size: 2.5em; font-weight: bold;"><?php echo $recurring_groups->num_rows; ?></div>
                        <div style="opacity: 0.9;">Recurring Groups</div>
                    </div>
                    <div>
                        <div style="font-size: 2.5em; font-weight: bold;"><?php echo $single_pending; ?></div>
                        <div style="opacity: 0.9;">Single Bookings</div>
                    </div>
                </div>
                <a href="manage_bookings.php?filter=pending" class="btn" 
                   style="background: white; color: #667eea; margin-top: 15px;">
                    View All Pending Bookings
                </a>
            </div>
            
            <?php if ($recurring_groups->num_rows > 0): ?>
            <h3>🔄 Recurring Booking Groups Pending Approval</h3>
            
            <?php while ($group = $recurring_groups->fetch_assoc()): 
                $safe_group_id = preg_replace('/[^a-z0-9]/i', '', $group['recurring_group_id']);
                $pending_ids = explode(',', $group['pending_ids']);
                $pending_dates = explode(',', $group['pending_dates']);
            ?>
            <div class="recurring-group-card">
                <div class="group-header">
                    <div>
                        <h3 style="margin: 0; color: #2c3e50;"><?php echo htmlspecialchars($group['room_name']); ?></h3>
                        <p style="margin: 5px 0; color: #7f8c8d;">
                            <?php echo htmlspecialchars($group['building']); ?>
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: #f39c12; color: white; padding: 8px 15px; border-radius: 20px; font-weight: bold; display: inline-block;">
                            <?php echo $group['pending_count']; ?> / <?php echo $group['total_bookings']; ?> Pending
                        </div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
                    <div>
                        <p><strong>Requested By:</strong> <?php echo htmlspecialchars($group['user_name']); ?> 
                           (<?php echo htmlspecialchars($group['role']); ?>)</p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($group['email']); ?></p>
                        <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($group['start_time'])) . ' - ' . 
                                                            date('g:i A', strtotime($group['end_time'])); ?></p>
                    </div>
                    <div>
                        <p><strong>Date Range:</strong> 
                           <?php echo date('M j, Y', strtotime($group['first_date'])) . ' to ' . 
                                      date('M j, Y', strtotime($group['last_date'])); ?>
                        </p>
                        <p><strong>Purpose:</strong> <?php echo htmlspecialchars($group['purpose']); ?></p>
                    </div>
                </div>
                
                <h4>Pending Dates:</h4>
                <div class="pending-dates-list">
                    <div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #dee2e6;">
                        <input type="checkbox" id="select-all-<?php echo $safe_group_id; ?>" 
                               onchange="toggleSelectAll('<?php echo $group['recurring_group_id']; ?>')"
                               style="width: 18px; height: 18px; margin-right: 10px;">
                        <label for="select-all-<?php echo $safe_group_id; ?>" style="cursor: pointer; font-weight: bold;">
                            Select All
                        </label>
                    </div>
                    <?php for ($i = 0; $i < count($pending_ids); $i++): ?>
                    <div class="date-item">
                        <label style="margin: 0; cursor: pointer; display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" 
                                   class="booking-check-<?php echo $safe_group_id; ?>" 
                                   value="<?php echo $pending_ids[$i]; ?>"
                                   style="width: 18px; height: 18px;">
                            <strong><?php echo date('l, F j, Y', strtotime($pending_dates[$i])); ?></strong>
                        </label>
                        <span style="color: #f39c12; font-weight: bold;">PENDING</span>
                    </div>
                    <?php endfor; ?>
                </div>
                
                <div class="bulk-action-buttons">
                    <button onclick="approveAllInGroup('<?php echo $group['recurring_group_id']; ?>', <?php echo $group['pending_count']; ?>)" 
                            class="btn btn-success" style="flex: 1;">
                        ✓ Approve All (<?php echo $group['pending_count']; ?>)
                    </button>
                    <button onclick="approveSelected('<?php echo $group['recurring_group_id']; ?>')" 
                            class="btn" style="flex: 1;">
                        ✓ Approve Selected
                    </button>
                    <button onclick="rejectSelected('<?php echo $group['recurring_group_id']; ?>')" 
                            class="btn btn-warning" style="flex: 1;">
                        ✗ Reject Selected
                    </button>
                    <button onclick="rejectAllInGroup('<?php echo $group['recurring_group_id']; ?>', <?php echo $group['pending_count']; ?>)" 
                            class="btn btn-danger" style="flex: 1;">
                        ✗ Reject All
                    </button>
                </div>
            </div>
            <?php endwhile; ?>
            
            <?php else: ?>
            <div style="background: white; padding: 60px; border-radius: 10px; text-align: center;">
                <p style="font-size: 1.2em; color: #7f8c8d; margin-bottom: 20px;">
                    ✓ No recurring bookings pending approval
                </p>
                <a href="manage_bookings.php" class="btn">
                    View All Bookings
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
