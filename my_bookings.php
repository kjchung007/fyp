<?php
require_once 'config.php';

if (!is_logged_in()) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$status_filter = $_GET['status'] ?? '';

// Get pending count for sidebar badge
$pending_stats_query = "SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
                FROM bookings 
                WHERE user_id = ?";
$pending_stats_stmt = $conn->prepare($pending_stats_query);
$pending_stats_stmt->bind_param("i", $user_id);
$pending_stats_stmt->execute();
$sidebar_stats = $pending_stats_stmt->get_result()->fetch_assoc();

// Build query to get bookings with recurring group info
$query = "SELECT b.*, r.room_name, r.building, r.floor,
          (SELECT COUNT(*) FROM bookings WHERE recurring_group_id = b.recurring_group_id AND recurring_group_id IS NOT NULL) as recurring_count
          FROM bookings b
          JOIN rooms r ON b.room_id = r.room_id
          WHERE b.user_id = ?";

if ($status_filter) {
    $query .= " AND b.status = '" . sanitize_input($status_filter) . "'";
}

$query .= " ORDER BY b.booking_date DESC, b.start_time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$bookings = $stmt->get_result();

// Group bookings by recurring_group_id
$grouped_bookings = [];
$single_bookings = [];

while ($booking = $bookings->fetch_assoc()) {
    if ($booking['recurring_group_id']) {
        $group_id = $booking['recurring_group_id'];
        if (!isset($grouped_bookings[$group_id])) {
            $grouped_bookings[$group_id] = [];
        }
        $grouped_bookings[$group_id][] = $booking;
    } else {
        $single_bookings[] = $booking;
    }
}

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM bookings 
                WHERE user_id = ?";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$booking_stats = $stats_stmt->get_result()->fetch_assoc();

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - FCSIT</title>
    <link rel="stylesheet" href="style.css">
    <script>
        function cancelBooking(bookingId) {
            if (confirm('Are you sure you want to cancel this booking?')) {
                window.location.href = 'actions/cancel_booking.php?id=' + bookingId;
            }
        }
        
        function cancelRecurringGroup(groupId) {
            const action = prompt('Cancel options:\n1. Cancel ALL future bookings in this series\n2. Cancel only specific dates\n\nEnter 1 or 2:');
            
            if (action === '1') {
                if (confirm('This will cancel ALL future bookings in this recurring series. Continue?')) {
                    window.location.href = 'actions/cancel_recurring.php?group_id=' + encodeURIComponent(groupId) + '&mode=all';
                }
            } else if (action === '2') {
                alert('Please select individual dates to cancel from the list below.');
            }
        }
        
        function toggleSelectAll(groupId) {
            const checkboxes = document.querySelectorAll('.booking-checkbox-' + groupId.replace(/[^a-z0-9]/gi, ''));
            const selectAll = document.getElementById('select-all-' + groupId.replace(/[^a-z0-9]/gi, ''));
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }
        
        function cancelSelected(groupId) {
            const checkboxes = document.querySelectorAll('.booking-checkbox-' + groupId.replace(/[^a-z0-9]/gi, '') + ':checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (selectedIds.length === 0) {
                alert('Please select at least one booking to cancel.');
                return;
            }
            
            if (confirm('Cancel ' + selectedIds.length + ' selected booking(s)?')) {
                window.location.href = 'actions/cancel_recurring.php?booking_ids=' + selectedIds.join(',') + '&mode=selected';
            }
        }
    </script>
</head>
<body>
    <div class="wrapper">
        <nav id="sidebar">
            <div class="sidebar-header">
                <h3>FCSIT</h3>
                <p>
                    Room Booking System
                    <br><i data-lucide="user" style="width:13px;height:13px"></i>  <?php echo htmlspecialchars($_SESSION['name']); ?>
                </p>
            </div>
            <ul class="list-unstyled components">
                <li><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a></li>
                <li><a href="browse_rooms.php"><i data-lucide="door-open"></i> <span>Browse Rooms</span></a></li>
                <li><a href="room_calendar.php"><i data-lucide="calendar-days"></i> <span>Room Calendar</span></a></li>
                <li class="active"><a href="my_bookings.php"><i data-lucide="clipboard-list"></i> <span>My Bookings</span>
                    <?php if ($sidebar_stats['pending_count'] > 0): ?>
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $sidebar_stats['pending_count']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="report_issue.php"><i data-lucide="alert-triangle"></i> <span>Report Issue</span></a></li>
                <?php if (check_role('admin')): ?>
                <li><a href="admin/dashboard.php"><i data-lucide="shield"></i> <span>Admin Panel</span></a></li>
                <?php endif; ?>
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
                        <h2>My Bookings</h2>
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
                            <a href="actions/logout.php" class="dropdown-item danger">
                                <i data-lucide="log-out"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-header">
                <h1>My Bookings</h1>
                <p>View and manage all your room booking requests.</p>
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
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Total Bookings</div>
                            <div class="stat-value"><?php echo $booking_stats['total']; ?></div>
                        </div>
                        <div class="stat-icon purple"><i data-lucide="calendar-check" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Pending</div>
                            <div class="stat-value"><?php echo $booking_stats['pending']; ?></div>
                        </div>
                        <div class="stat-icon orange"><i data-lucide="clock" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Approved</div>
                            <div class="stat-value"><?php echo $booking_stats['approved']; ?></div>
                        </div>
                        <div class="stat-icon green"><i data-lucide="check-circle" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Rejected</div>
                            <div class="stat-value"><?php echo $booking_stats['rejected']; ?></div>
                        </div>
                        <div class="stat-icon red"><i data-lucide="x-circle" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="filter-sidebar">
                <form method="GET" action="my_bookings.php" style="display:flex;gap:12px;align-items:end;">
                    <div class="form-group" style="flex:1;margin-bottom:0;">
                        <label>Filter by Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <button type="submit" class="btn">Apply</button>
                    <a href="my_bookings.php" class="btn btn-outline">Clear</a>
                </form>
            </div>
            
            <!-- Recurring Bookings Groups -->
            <?php if (count($grouped_bookings) > 0): ?>
            <div class="section-label"><i data-lucide="repeat" style="width:14px;height:14px;"></i> Recurring Bookings</div>
            <?php foreach ($grouped_bookings as $group_id => $group_bookings): 
                $first_booking = $group_bookings[0];
                $active_count = count(array_filter($group_bookings, function($b) {
                    return $b['status'] !== 'cancelled' && $b['status'] !== 'rejected';
                }));
            ?>
            <div class="recurring-group-modern">
                <div class="recurring-group-header">
                    <div>
                        <h4><?php echo htmlspecialchars($first_booking['room_name']); ?>
                            <span class="recurring-tag"><?php echo count($group_bookings); ?> bookings</span>
                        </h4>
                        <div style="font-size:0.82em;color:var(--text-secondary);margin-top:2px;">
                            <?php echo htmlspecialchars($first_booking['building']); ?>, Floor <?php echo $first_booking['floor']; ?>
                        </div>
                    </div>
                    <span class="badge badge-approved"><?php echo $active_count; ?> active</span>
                </div>
                
                <div class="recurring-group-body">
                    <div class="recurring-group-info">
                        <strong>Time:</strong> <?php echo date('g:i A', strtotime($first_booking['start_time'])) . ' - ' . 
                                                    date('g:i A', strtotime($first_booking['end_time'])); ?> · 
                        <strong>Purpose:</strong> <?php echo htmlspecialchars($first_booking['purpose']); ?>
                    </div>
                    
                    <?php 
                    $safe_group_id = preg_replace('/[^a-z0-9]/i', '', $group_id);
                    foreach ($group_bookings as $booking): 
                        $is_past = strtotime($booking['booking_date']) < time();
                        $can_cancel = ($booking['status'] === 'pending' || $booking['status'] === 'approved') && !$is_past;
                    ?>
                    <div class="recurring-booking-item">
                        <div class="recurring-booking-left">
                            <?php if ($can_cancel): ?>
                            <input type="checkbox" class="booking-checkbox-<?php echo $safe_group_id; ?>" 
                                   value="<?php echo $booking['booking_id']; ?>" 
                                   style="width:16px;height:16px;cursor:pointer;">
                            <?php endif; ?>
                            <span><strong><?php echo date('D, M j, Y', strtotime($booking['booking_date'])); ?></strong></span>
                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                            <?php if ($is_past): ?>
                            <span class="badge badge-low">Past</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($can_cancel): ?>
                        <button onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" class="btn btn-outline" style="padding:5px 12px;font-size:0.8em;">
                            <i data-lucide="x" style="width:12px;height:12px;"></i> Cancel
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if ($active_count > 0): ?>
                    <div class="recurring-bulk-actions">
                        <input type="checkbox" id="select-all-<?php echo $safe_group_id; ?>" 
                               style="width:16px;height:16px;cursor:pointer;"
                               onchange="toggleSelectAll('<?php echo $group_id; ?>')">
                        <label for="select-all-<?php echo $safe_group_id; ?>" style="font-size:0.82em;font-weight:500;cursor:pointer;margin:0;">
                            Select All
                        </label>
                        <div style="margin-left:auto;display:flex;gap:8px;">
                            <button onclick="cancelSelected('<?php echo $group_id; ?>')" class="btn btn-warning" style="padding:6px 14px;font-size:0.82em;">
                                Cancel Selected
                            </button>
                            <button onclick="cancelRecurringGroup('<?php echo $group_id; ?>')" class="btn btn-danger" style="padding:6px 14px;font-size:0.82em;">
                                Cancel All Future
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Single Bookings -->
            <?php if (count($single_bookings) > 0): ?>
            <div class="section-label"><i data-lucide="calendar" style="width:14px;height:14px;"></i> Individual Bookings</div>
            <div class="card-list">
                <?php foreach ($single_bookings as $booking): 
                    $is_past = strtotime($booking['booking_date']) < time();
                    $can_cancel = ($booking['status'] === 'pending' || $booking['status'] === 'approved') && !$is_past;
                ?>
                <div class="card-row">
                    <div class="card-row-left">
                        <div class="card-row-icon">
                            <i data-lucide="door-open" style="width:18px;height:18px;"></i>
                        </div>
                        <div class="card-row-info">
                            <h3><?php echo htmlspecialchars($booking['room_name']); ?></h3>
                            <div class="card-row-meta">
                                <span><i data-lucide="calendar" style="width:12px;height:12px;"></i> <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></span>
                                <span><i data-lucide="clock" style="width:12px;height:12px;"></i> <?php echo date('g:i A', strtotime($booking['start_time'])) . ' - ' . date('g:i A', strtotime($booking['end_time'])); ?></span>
                                <span><i data-lucide="map-pin" style="width:12px;height:12px;"></i> <?php echo htmlspecialchars($booking['building']) . ', Floor ' . $booking['floor']; ?></span>
                            </div>
                            <div class="card-row-purpose">Purpose: <?php echo htmlspecialchars(substr($booking['purpose'], 0, 60)) . (strlen($booking['purpose']) > 60 ? '...' : ''); ?></div>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <span class="status-badge status-<?php echo $booking['status']; ?>">
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                        <?php if ($is_past): ?>
                        <span class="badge badge-low">Past</span>
                        <?php endif; ?>
                        <?php if ($can_cancel): ?>
                        <button onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" class="btn-icon danger" title="Cancel booking">
                            <i data-lucide="x" style="width:16px;height:16px;"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (count($single_bookings) === 0 && count($grouped_bookings) === 0): ?>
            <div class="empty-state">
                <i data-lucide="inbox" style="width:40px;height:40px;opacity:0.3;margin-bottom:12px;"></i>
                <p>No bookings found.</p>
                <a href="browse_rooms.php" class="btn btn-success" style="margin-top: 12px;">Browse Rooms to Book</a>
            </div>
            <?php endif; ?>
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
