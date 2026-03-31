<?php
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$sidebar_stats = [];

// Pending items for sidebar
$sidebar_stats['pending_bookings'] = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'")->fetch_assoc()['count'];
$sidebar_stats['pending_reports'] = $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE status = 'pending'")->fetch_assoc()['count'];

// Get filter parameters
$status_filter = $_GET['filter'] ?? '';
$date_filter = $_GET['date'] ?? '';
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';

// Get recurring booking groups with proper status filtering for mixed statuses
$recurring_query = "SELECT 
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
                    SUM(CASE WHEN b.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN b.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
                    FROM bookings b
                    JOIN rooms r ON b.room_id = r.room_id
                    JOIN users u ON b.user_id = u.user_id
                    WHERE b.recurring_group_id IS NOT NULL";

if ($role_filter) {
    $recurring_query .= " AND u.role = '" . sanitize_input($role_filter) . "'";
}

$recurring_query .= " GROUP BY b.recurring_group_id, r.room_name, r.building, u.name, u.email, u.role, b.start_time, b.end_time, b.purpose";

// Add status filter for recurring groups - show groups that have at least one booking with the selected status
if ($status_filter === 'pending') {
    $recurring_query .= " HAVING pending_count > 0";
} elseif ($status_filter === 'approved') {
    $recurring_query .= " HAVING approved_count > 0";
} elseif ($status_filter === 'rejected') {
    $recurring_query .= " HAVING rejected_count > 0";
} elseif ($status_filter === 'cancelled') {
    $recurring_query .= " HAVING cancelled_count > 0";
}

$recurring_query .= " ORDER BY first_date DESC";
$recurring_groups = $conn->query($recurring_query);

// Build query for individual bookings
$query = "SELECT b.*, u.name as user_name, u.email, u.role, r.room_name, r.building,
          (SELECT COUNT(*) FROM bookings WHERE recurring_group_id = b.recurring_group_id) as group_size
          FROM bookings b
          JOIN users u ON b.user_id = u.user_id
          JOIN rooms r ON b.room_id = r.room_id
          WHERE b.recurring_group_id IS NULL";

if ($status_filter) {
    $query .= " AND b.status = '" . sanitize_input($status_filter) . "'";
}

if ($date_filter) {
    $query .= " AND b.booking_date = '" . sanitize_input($date_filter) . "'";
}

if ($search) {
    $search_term = sanitize_input($search);
    $query .= " AND (u.name LIKE '%{$search_term}%' OR r.room_name LIKE '%{$search_term}%')";
}

if ($role_filter) {
    $query .= " AND u.role = '" . sanitize_input($role_filter) . "'";
}

$query .= " ORDER BY b.booking_date DESC, b.start_time DESC";

$single_bookings = $conn->query($query);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN recurring_group_id IS NOT NULL THEN 1 ELSE 0 END) as recurring_total
                FROM bookings";
$stats = $conn->query($stats_query)->fetch_assoc();

// Get role-based booking counts
$role_stats_query = "SELECT u.role, COUNT(*) as count 
                     FROM bookings b 
                     JOIN users u ON b.user_id = u.user_id 
                     GROUP BY u.role";
$role_stats_result = $conn->query($role_stats_query);
$role_stats = [];
while ($rs = $role_stats_result->fetch_assoc()) {
    $role_stats[$rs['role']] = $rs['count'];
}

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

// Helper function for role icon
function getRoleIcon($role) {
    switch ($role) {
        case 'student': return 'graduation-cap';
        case 'lecturer': return 'theater';
        case 'admin': return 'shield-user';
        case 'super_admin': return 'shield-check';
        case 'staff': return 'briefcase';
        default: return 'user';
    }
}

// Helper function for role color class
function getRoleColorClass($role) {
    switch ($role) {
        case 'student': return 'blue';
        case 'lecturer': return 'purple';
        case 'admin': return 'orange';
        case 'super_admin': return 'red';
        case 'staff': return 'green';
        default: return 'muted';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - Admin</title>
    <link rel="stylesheet" href="../style.css">
    <script>
        function toggleRecurringGroup(groupId) {
            const row = document.getElementById('expand-' + groupId);
            const icon = document.getElementById('icon-' + groupId);
            
            if (row.classList.contains('expanded')) {
                row.classList.remove('expanded');
                icon.classList.remove('expanded-icon');
            } else {
                row.classList.add('expanded');
                icon.classList.add('expanded-icon');
            }
        }
        
        function approveBooking(bookingId) {
            if (confirm('Approve this booking request?')) {
                window.location.href = '../actions/admin_booking_action.php?action=approve&id=' + bookingId;
            }
        }
        
        function rejectBooking(bookingId) {
            const reason = prompt('Reason for rejection (optional):');
            if (reason !== null) {
                window.location.href = '../actions/admin_booking_action.php?action=reject&id=' + bookingId + '&reason=' + encodeURIComponent(reason);
            }
        }
        
        function deleteBooking(bookingId) {
            if (confirm('Are you sure you want to delete this booking? This action cannot be undone.')) {
                window.location.href = '../actions/admin_booking_action.php?action=delete&id=' + bookingId;
            }
        }
        
        function approveAllInGroup(groupId) {
            if (confirm('Approve all pending bookings in this recurring series?')) {
                window.location.href = '../actions/admin_bulk_approval.php?action=approve_group&group_id=' + encodeURIComponent(groupId);
            }
        }
        
        function rejectAllInGroup(groupId) {
            const reason = prompt('Reject all pending bookings in this series?\n\nReason (optional):');
            if (reason !== null) {
                window.location.href = '../actions/admin_bulk_approval.php?action=reject_group&group_id=' + encodeURIComponent(groupId) + '&reason=' + encodeURIComponent(reason);
            }
        }
        
        function approveSelected(groupId) {
            const checkboxes = document.querySelectorAll('.check-' + groupId.replace(/[^a-z0-9]/gi, '') + ':checked');
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
            const checkboxes = document.querySelectorAll('.check-' + groupId.replace(/[^a-z0-9]/gi, '') + ':checked');
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
            const selectAll = document.getElementById('select-all-' + groupId.replace(/[^a-z0-9]/gi, ''));
            const checkboxes = document.querySelectorAll('.check-' + groupId.replace(/[^a-z0-9]/gi, ''));
            
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        }
    </script>
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>FCSIT</h3>
                <p>
                    Room Booking Administrative System
                    <br><i data-lucide="user" style="width:13px;height:13px"></i>  <?php echo htmlspecialchars($_SESSION['name']); ?>
                </p>
            </div>
            <ul class="list-unstyled components">
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a></li>
                <li class="active"><a href="manage_bookings.php"><i data-lucide="calendar-check"></i> <span>Manage Bookings</span>
                    <?php if ($sidebar_stats['pending_bookings'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $sidebar_stats['pending_bookings']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="manage_rooms.php"><i data-lucide="door-open"></i> <span>Manage Rooms</span></a></li>
                <li><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
                <li><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
                    <?php if ($sidebar_stats['pending_reports'] > 0): ?>
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $sidebar_stats['pending_reports']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="system_logs.php"><i data-lucide="scroll-text"></i> <span>System Logs</span></a></li>
                <li><a href="calendar.php"><i data-lucide="calendar-days"></i> <span>Calendar View</span></a></li>
                <li><a href="analytics.php"><i data-lucide="bar-chart-3"></i> <span>Analytics</span></a></li>
                <li><a href="../dashboard.php"><i data-lucide="arrow-left"></i> <span>User View</span></a></li>
            </ul>
        </nav>
        
        <div id="content">
            <!-- Header -->
            <div class="top-header">
                <div class="header-left">
                    <div class="toggle-sidebar" id="toggleSidebar">
                        <i data-lucide="menu"></i>
                    </div>
                    <div class="page-title">
                        <h2>Manage Bookings</h2>
                    </div>
                </div>
                <div class="header-right">
                    <!-- Notifications -->
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="header-icon-btn" id="notificationTrigger">
                            <i data-lucide="bell"></i>
                            <?php if ($notif_count > 0): ?>
                            <span class="notification-badge"><?php echo $notif_count > 9 ? '9+' : $notif_count; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="notification-menu">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <?php if ($notif_count > 0): ?>
                                <a href="#" class="mark-all-read" onclick="markAllNotificationsRead(event)">Mark all as read</a>
                                <?php endif; ?>
                            </div>
                            <div id="notificationList">
                                <?php
                                $notif_list_query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
                                $notif_list_stmt = $conn->prepare($notif_list_query);
                                $notif_list_stmt->bind_param("i", $user_id);
                                $notif_list_stmt->execute();
                                $notif_list_result = $notif_list_stmt->get_result();
                                
                                if ($notif_list_result->num_rows > 0):
                                    while ($notif = $notif_list_result->fetch_assoc()):
                                ?>
                                <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notif['notification_id']; ?>" onclick="markNotificationRead(<?php echo $notif['notification_id']; ?>, this)">
                                    <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                    <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="notification-time"><?php echo date('M j, g:i a', strtotime($notif['created_at'])); ?></div>
                                </div>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                <div class="empty-notifications">
                                    <i data-lucide="bell-off"></i>
                                    <p>No notifications yet</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profile -->
                    <div class="profile-dropdown" id="profileDropdown">
                        <div class="profile-trigger" id="profileTrigger">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                            </div>
                            <div class="profile-info">
                                <span class="profile-name"><?php echo htmlspecialchars(explode(' ', $_SESSION['name'])[0]); ?></span>
                                <span class="profile-role"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></span>
                            </div>
                            <i data-lucide="chevron-down" class="dropdown-arrow"></i>
                        </div>
                        <div class="dropdown-menu">
                            <div class="dropdown-header">
                                <div class="dropdown-name"><?php echo htmlspecialchars($_SESSION['name']); ?></div>
                                <div class="dropdown-email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
                            </div>
                            <a href="profile.php" class="dropdown-item">
                                <i data-lucide="user"></i>
                                <span>My Profile</span>
                            </a>
                            <a href="settings.php" class="dropdown-item">
                                <i data-lucide="settings"></i>
                                <span>Settings</span>
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="../actions/logout.php" class="dropdown-item danger">
                                <i data-lucide="log-out"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="admin-header">
                <h2>Manage Bookings</h2>
                <p>Review, approve, or reject room booking requests.</p>
            </div>
            
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
            
            <!-- Statistics -->
            <div class="card-container">
                <div class="stat-card-modern">
                    <div class="stat-icon purple"><i data-lucide="calendar" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Bookings</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon orange"><i data-lucide="clock" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green"><i data-lucide="check-circle-2" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['approved']; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon blue"><i data-lucide="repeat" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $recurring_groups->num_rows; ?></div>
                        <div class="stat-label">Recurring Series</div>
                    </div>
                </div>
            </div>

            <!-- Role Filter Cards -->
            <div class="section-label">Filter by User Role</div>
            <div class="card-container" style="margin-top: 6px;">
                <a href="manage_bookings.php?<?php echo $status_filter ? 'filter=' . $status_filter . '&' : ''; ?><?php echo $date_filter ? 'date=' . $date_filter . '&' : ''; ?><?php echo $search ? 'search=' . urlencode($search) : ''; ?>" class="stat-card-modern role-filter-card <?php echo $role_filter === '' ? 'role-filter-active' : ''; ?>">
                    <div class="stat-icon muted"><i data-lucide="users" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo array_sum($role_stats); ?></div>
                        <div class="stat-label">All Roles</div>
                    </div>
                </a>
                <a href="manage_bookings.php?role=admin<?php echo $status_filter ? '&filter=' . $status_filter : ''; ?><?php echo $date_filter ? '&date=' . $date_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="stat-card-modern role-filter-card <?php echo $role_filter === 'admin' ? 'role-filter-active' : ''; ?>">
                    <div class="stat-icon orange"><i data-lucide="shield-user" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $role_stats['admin'] ?? 0; ?></div>
                        <div class="stat-label">Admin</div>
                    </div>
                </a>
                <a href="manage_bookings.php?role=student<?php echo $status_filter ? '&filter=' . $status_filter : ''; ?><?php echo $date_filter ? '&date=' . $date_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="stat-card-modern role-filter-card <?php echo $role_filter === 'student' ? 'role-filter-active' : ''; ?>">
                    <div class="stat-icon blue"><i data-lucide="graduation-cap" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $role_stats['student'] ?? 0; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                </a>
                <a href="manage_bookings.php?role=lecturer<?php echo $status_filter ? '&filter=' . $status_filter : ''; ?><?php echo $date_filter ? '&date=' . $date_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="stat-card-modern role-filter-card <?php echo $role_filter === 'lecturer' ? 'role-filter-active' : ''; ?>">
                    <div class="stat-icon purple"><i data-lucide="theater" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $role_stats['lecturer'] ?? 0; ?></div>
                        <div class="stat-label">Lecturers</div>
                    </div>
                </a>
                <a href="manage_bookings.php?role=staff<?php echo $status_filter ? '&filter=' . $status_filter : ''; ?><?php echo $date_filter ? '&date=' . $date_filter : ''; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="stat-card-modern role-filter-card <?php echo $role_filter === 'staff' ? 'role-filter-active' : ''; ?>">
                    <div class="stat-icon green"><i data-lucide="briefcase" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $role_stats['staff'] ?? 0; ?></div>
                        <div class="stat-label">Staff</div>
                    </div>
                </a>
            </div>
            
            <!-- Filters -->
            <div class="filter-sidebar">
                <form method="GET" action="manage_bookings.php">
                    <?php if ($role_filter): ?>
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">
                    <?php endif; ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Status</label>
                            <select name="filter" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo $date_filter; ?>">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Name or room..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn">Filter</button>
                            <a href="manage_bookings.php" class="btn btn-outline">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Bookings as Card List -->
            <div class="card-list">
                <?php 
                // Display recurring groups first
                if ($recurring_groups->num_rows > 0):
                    $recurring_groups->data_seek(0);
                    while ($group = $recurring_groups->fetch_assoc()): 
                        $safe_id = preg_replace('/[^a-z0-9]/i', '', $group['recurring_group_id']);
                        $details_query = "SELECT * FROM bookings 
                                         WHERE recurring_group_id = '" . $group['recurring_group_id'] . "' 
                                         ORDER BY booking_date";
                        $details = $conn->query($details_query);
                        $role_icon = getRoleIcon($group['role']);
                        $role_color = getRoleColorClass($group['role']);
                ?>
                <div class="card-row clickable" onclick="toggleRecurringGroup('<?php echo $safe_id; ?>')" style="border-radius: var(--radius) var(--radius) <?php echo 'var(--radius) var(--radius)'; ?>;">
                    <div class="card-row-left">
                        <span class="expand-icon" id="icon-<?php echo $safe_id; ?>">▶</span>
                        <div class="card-row-icon <?php echo $role_color; ?>">
                            <i data-lucide="<?php echo $role_icon; ?>" style="width:20px;height:20px"></i>
                        </div>
                        <div class="card-row-info">
                            <h3>
                                <?php echo htmlspecialchars($group['room_name']); ?>
                                <span class="recurring-tag"><i data-lucide="repeat" style="width:10px;height:10px"></i> Recurring</span>
                            </h3>
                            <div class="card-row-meta">
                                <span><i data-lucide="user" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($group['user_name']); ?></span>
                                <span class="role-badge role-<?php echo $group['role']; ?>"><i data-lucide="<?php echo $role_icon; ?>" style="width:10px;height:10px"></i> <?php echo ucfirst($group['role']); ?></span>
                                <span><i data-lucide="calendar" style="width:12px;height:12px"></i> <?php echo date('M j', strtotime($group['first_date'])) . ' → ' . date('M j, Y', strtotime($group['last_date'])); ?></span>
                                <span><i data-lucide="clock" style="width:12px;height:12px"></i> <?php echo date('g:i A', strtotime($group['start_time'])) . ' - ' . date('g:i A', strtotime($group['end_time'])); ?></span>
                            </div>
                            <div class="card-row-purpose">Purpose: <?php echo htmlspecialchars(substr($group['purpose'], 0, 60)) . (strlen($group['purpose']) > 60 ? '...' : ''); ?></div>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <?php if ($group['pending_count'] > 0): ?>
                        <span class="status-badge status-pending"><?php echo $group['pending_count']; ?> Pending</span>
                        <?php endif; ?>
                        <?php if ($group['approved_count'] > 0): ?>
                        <span class="status-badge status-approved"><?php echo $group['approved_count']; ?> Approved</span>
                        <?php endif; ?>
                        <?php if ($group['rejected_count'] > 0): ?>
                        <span class="status-badge status-rejected"><?php echo $group['rejected_count']; ?> Rejected</span>
                        <?php endif; ?>
                        <?php if ($group['cancelled_count'] > 0): ?>
                        <span class="status-badge status-cancelled"><?php echo $group['cancelled_count']; ?> Cancelled</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Expandable section -->
                <div id="expand-<?php echo $safe_id; ?>" class="card-expand">
                    <div class="card-expand-header">
                        <span>Recurring Series (<?php echo $group['total_bookings']; ?> dates)</span>
                        <?php if ($group['pending_count'] > 0): ?>
                        <div class="bulk-actions">
                            <input type="checkbox" id="select-all-<?php echo $safe_id; ?>" 
                                   onchange="toggleSelectAll('<?php echo $group['recurring_group_id']; ?>')"
                                   style="width:14px;height:14px; cursor:pointer;">
                            <label for="select-all-<?php echo $safe_id; ?>" style="margin:0;font-size:0.78em;cursor:pointer;">Select All</label>
                            <button onclick="approveSelected('<?php echo $group['recurring_group_id']; ?>')" class="btn btn-success" style="padding:4px 10px;font-size:0.78em;">Approve Selected</button>
                            <button onclick="rejectSelected('<?php echo $group['recurring_group_id']; ?>')" class="btn btn-warning" style="padding:4px 10px;font-size:0.78em;">Reject Selected</button>
                            <button onclick="approveAllInGroup('<?php echo $group['recurring_group_id']; ?>')" class="btn btn-outline" style="padding:4px 10px;font-size:0.78em;color:var(--success);border-color:var(--success-border);">Approve All</button>
                            <button onclick="rejectAllInGroup('<?php echo $group['recurring_group_id']; ?>')" class="btn btn-outline" style="padding:4px 10px;font-size:0.78em;color:var(--danger);border-color:var(--danger-border);">Reject All</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php while ($detail = $details->fetch_assoc()): ?>
                    <div class="expand-item">
                        <div class="expand-item-left">
                            <?php if ($detail['status'] === 'pending'): ?>
                            <input type="checkbox" class="check-<?php echo $safe_id; ?>" 
                                value="<?php echo $detail['booking_id']; ?>" 
                                style="width:14px;height:14px;cursor:pointer;" onclick="event.stopPropagation()">
                            <?php else: ?>
                            <span style="width:14px;height:14px;display:inline-block;"></span>
                            <?php endif; ?>
                            <span><?php echo date('M j, Y', strtotime($detail['booking_date'])); ?> — <?php echo date('g:i A', strtotime($detail['start_time'])) . ' - ' . date('g:i A', strtotime($detail['end_time'])); ?></span>
                        </div>
                        <div class="expand-item-right">
                            <span class="status-badge status-<?php echo $detail['status']; ?>"><?php echo ucfirst($detail['status']); ?></span>
                            <?php if ($detail['status'] === 'pending'): ?>
                            <button onclick="event.stopPropagation(); approveBooking(<?php echo $detail['booking_id']; ?>)" class="btn-icon success"><i data-lucide="check-circle-2" style="width:14px;height:14px"></i></button>
                            <button onclick="event.stopPropagation(); rejectBooking(<?php echo $detail['booking_id']; ?>)" class="btn-icon danger"><i data-lucide="x-circle" style="width:14px;height:14px"></i></button>
                            <?php else: ?>
                            <button onclick="event.stopPropagation(); deleteBooking(<?php echo $detail['booking_id']; ?>)" class="btn-icon" title="Delete"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php 
                    endwhile;
                endif;
                
                // Display single bookings
                if ($single_bookings->num_rows > 0):
                    while ($booking = $single_bookings->fetch_assoc()):
                        $role_icon = getRoleIcon($booking['role']);
                        $role_color = getRoleColorClass($booking['role']);
                ?>
                <div class="card-row">
                    <div class="card-row-left">
                        <div class="card-row-icon <?php echo $role_color; ?>">
                            <i data-lucide="<?php echo $role_icon; ?>" style="width:20px;height:20px"></i>
                        </div>
                        <div class="card-row-info">
                            <h3><?php echo htmlspecialchars($booking['room_name']); ?></h3>
                            <div class="card-row-meta">
                                <span><i data-lucide="user" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($booking['user_name']); ?></span>
                                <span class="role-badge role-<?php echo $booking['role']; ?>"><i data-lucide="<?php echo $role_icon; ?>" style="width:10px;height:10px"></i> <?php echo ucfirst($booking['role']); ?></span>
                                <span><i data-lucide="calendar" style="width:12px;height:12px"></i> <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></span>
                                <span><i data-lucide="clock" style="width:12px;height:12px"></i> <?php echo date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time'])); ?></span>
                            </div>
                            <div class="card-row-purpose">Purpose: <?php echo htmlspecialchars(substr($booking['purpose'], 0, 60)) . (strlen($booking['purpose']) > 60 ? '...' : ''); ?></div>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo ucfirst($booking['status']); ?></span>
                        <?php if ($booking['status'] === 'pending'): ?>
                        <button onclick="approveBooking(<?php echo $booking['booking_id']; ?>)" class="btn-icon success"><i data-lucide="check-circle-2" style="width:16px;height:16px"></i></button>
                        <button onclick="rejectBooking(<?php echo $booking['booking_id']; ?>)" class="btn-icon danger"><i data-lucide="x-circle" style="width:16px;height:16px"></i></button>
                        <?php else: ?>
                        <button onclick="deleteBooking(<?php echo $booking['booking_id']; ?>)" class="btn-icon" title="Delete"><i data-lucide="trash-2" style="width:16px;height:16px"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php 
                    endwhile;
                endif;
                
                if ($recurring_groups->num_rows === 0 && $single_bookings->num_rows === 0):
                ?>
                <div class="empty-state">No bookings found matching the filters.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        // Sidebar Toggle
        const toggleBtn = document.getElementById('toggleSidebar');
        const wrapper = document.querySelector('.wrapper');
        
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            wrapper.classList.add('sidebar-collapsed');
        }
        
        toggleBtn.addEventListener('click', function() {
            wrapper.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', wrapper.classList.contains('sidebar-collapsed'));
            setTimeout(() => lucide.createIcons(), 100);
        });
        
        // Profile Dropdown
        const profileDropdown = document.getElementById('profileDropdown');
        const profileTrigger = document.getElementById('profileTrigger');
        
        profileTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.classList.toggle('active');
            notificationDropdown.classList.remove('active');
        });
        
        // Notification Dropdown
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationTrigger = document.getElementById('notificationTrigger');
        
        notificationTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('active');
            profileDropdown.classList.remove('active');
        });
        
        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!profileDropdown.contains(e.target)) profileDropdown.classList.remove('active');
            if (!notificationDropdown.contains(e.target)) notificationDropdown.classList.remove('active');
        });
        
        // Mark notification as read
        function markNotificationRead(id, element) {
            fetch('actions/mark_notification_read.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    element.classList.remove('unread');
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        let count = parseInt(badge.textContent);
                        count > 1 ? badge.textContent = count - 1 : badge.remove();
                    }
                }
            });
        }
        
        // Mark all as read
        function markAllNotificationsRead(event) {
            if (event) event.preventDefault();
            fetch('actions/mark_all_notifications_read.php', {method: 'POST'})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item').forEach(item => item.classList.remove('unread'));
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                }
            });
        }
    </script>
</body>
</html>
