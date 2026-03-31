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

// Get date range (default: last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Overall Statistics
$overall_stats = $conn->query("SELECT 
    COUNT(*) as total_bookings,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM bookings
    WHERE booking_date BETWEEN '$start_date' AND '$end_date'")->fetch_assoc();

// Bookings by Status Over Time
$bookings_timeline = $conn->query("SELECT 
    DATE(created_at) as date,
    status,
    COUNT(*) as count
    FROM bookings
    WHERE booking_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(created_at), status
    ORDER BY date");

// Room Utilization
$room_utilization = $conn->query("SELECT 
    r.room_name,
    r.room_type,
    r.building,
    COUNT(b.booking_id) as total_bookings,
    SUM(CASE WHEN b.status = 'approved' THEN 1 ELSE 0 END) as approved_bookings,
    SUM(CASE WHEN b.status = 'approved' THEN 
        TIMESTAMPDIFF(HOUR, b.start_time, b.end_time) ELSE 0 END) as total_hours
    FROM rooms r
    LEFT JOIN bookings b ON r.room_id = b.room_id 
        AND b.booking_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY r.room_id
    ORDER BY total_bookings DESC");

// Most Active Users
$active_users = $conn->query("SELECT 
    u.name,
    u.email,
    u.role,
    COUNT(b.booking_id) as booking_count,
    SUM(CASE WHEN b.status = 'approved' THEN 1 ELSE 0 END) as approved_count
    FROM users u
    LEFT JOIN bookings b ON u.user_id = b.user_id 
        AND b.booking_date BETWEEN '$start_date' AND '$end_date'
    WHERE u.role IN ('lecturer', 'student')
    GROUP BY u.user_id
    HAVING booking_count > 0
    ORDER BY booking_count DESC
    LIMIT 10");

// Peak Booking Times
$peak_times = $conn->query("SELECT 
    HOUR(start_time) as hour,
    COUNT(*) as booking_count
    FROM bookings
    WHERE booking_date BETWEEN '$start_date' AND '$end_date'
    AND status = 'approved'
    GROUP BY HOUR(start_time)
    ORDER BY hour");

// Bookings by Day of Week
$day_distribution = $conn->query("SELECT 
    DAYNAME(booking_date) as day_name,
    DAYOFWEEK(booking_date) as day_num,
    COUNT(*) as booking_count
    FROM bookings
    WHERE booking_date BETWEEN '$start_date' AND '$end_date'
    AND status = 'approved'
    GROUP BY day_name, day_num
    ORDER BY day_num");

// Booking Duration Analysis
$duration_analysis = $conn->query("SELECT 
    TIMESTAMPDIFF(HOUR, start_time, end_time) as duration,
    COUNT(*) as count
    FROM bookings
    WHERE booking_date BETWEEN '$start_date' AND '$end_date'
    AND status = 'approved'
    GROUP BY duration
    ORDER BY duration");

// Maintenance Reports Summary
$maintenance_stats = $conn->query("SELECT 
    urgency,
    COUNT(*) as count
    FROM maintenance_reports
    WHERE DATE(reported_at) BETWEEN '$start_date' AND '$end_date'
    GROUP BY urgency")->fetch_all(MYSQLI_ASSOC);

// Recurring vs Single Bookings
$booking_types = $conn->query("SELECT 
    CASE WHEN recurring_group_id IS NULL THEN 'Single' ELSE 'Recurring' END as type,
    COUNT(*) as count
    FROM bookings
    WHERE booking_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY type")->fetch_all(MYSQLI_ASSOC);

// Prepare data for charts
$timeline_data = [];
while ($row = $bookings_timeline->fetch_assoc()) {
    $timeline_data[] = $row;
}

$room_data = [];
while ($row = $room_utilization->fetch_assoc()) {
    $room_data[] = $row;
}

$peak_data = [];
while ($row = $peak_times->fetch_assoc()) {
    $peak_data[] = $row;
}

$day_data = [];
while ($row = $day_distribution->fetch_assoc()) {
    $day_data[] = $row;
}

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
    <title>Analytics & Reports - Admin</title>
    <link rel="stylesheet" href="../style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <li><a href="manage_bookings.php"><i data-lucide="calendar-check"></i> <span>Manage Bookings</span>
                    <?php if ($sidebar_stats['pending_bookings'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $sidebar_stats['pending_bookings']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="manage_rooms.php"><i data-lucide="door-open"></i> <span>Manage Rooms</span></a></li>
                <li><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
                <li><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
                    <?php if ($sidebar_stats['pending_reports'] > 0): ?>
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $stats['pending_reports']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li ><a href="system_logs.php"><i data-lucide="scroll-text"></i> <span>System Logs</span></a></li>
                <li><a href="calendar.php"><i data-lucide="calendar-days"></i> <span>Calendar View</span></a></li>
                <li class="active"><a href="analytics.php"><i data-lucide="bar-chart-3"></i> <span>Analytics</span></a></li>
                <li><a href="../dashboard.php"><i data-lucide="arrow-left"></i> <span>User View</span></a></li>
            </ul>
        </nav>
        
        <div id="content">
            <div class="top-header">
                <div class="header-left">
                    <div class="toggle-sidebar" id="toggleSidebar">
                        <i data-lucide="menu"></i>
                    </div>
                    <div class="page-title">
                        <h2>Analytics</h2>
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
            <div class="stats-banner">
                <h1><i data-lucide="bar-chart-3" style="width:28px;height:28px;vertical-align:middle;margin-right:8px;"></i> Analytics & Reports</h1>
                <p>Comprehensive insights into room booking trends and utilization</p>
            </div>
            
            <!-- Date Range Filter -->
            <div class="filter-sidebar">
                <form method="GET" action="analytics.php">
                    <div style="display: flex; gap: 12px; align-items: end;">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?php echo $start_date; ?>" required>
                        </div>
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?php echo $end_date; ?>" required>
                        </div>
                        <button type="submit" class="btn">Update Report</button>
                        <button type="button" onclick="window.print()" class="btn btn-outline"><i data-lucide="printer" style="width:14px;height:14px"></i> Print</button>
                    </div>
                </form>
            </div>
            
            <!-- Key Metrics -->
            <div class="card-container">
                <div class="metric-card" style="border-left-color: var(--info);">
                    <div class="metric-value"><?php echo $overall_stats['total_bookings']; ?></div>
                    <div class="metric-label">Total Bookings</div>
                </div>
                <div class="metric-card" style="border-left-color: var(--success);">
                    <div class="metric-value"><?php echo $overall_stats['approved']; ?></div>
                    <div class="metric-label">Approved</div>
                    <small style="color:var(--text-secondary);"><?php echo $overall_stats['total_bookings'] > 0 ? 
                           round($overall_stats['approved']/$overall_stats['total_bookings']*100) : 0; ?>%</small>
                </div>
                <div class="metric-card" style="border-left-color: var(--warning);">
                    <div class="metric-value"><?php echo $overall_stats['pending']; ?></div>
                    <div class="metric-label">Pending Review</div>
                </div>
                <div class="metric-card" style="border-left-color: var(--danger);">
                    <div class="metric-value"><?php echo $overall_stats['rejected']; ?></div>
                    <div class="metric-label">Rejected</div>
                </div>
            </div>
            
            <!-- Charts Grid -->
            <div class="analytics-grid">
                <!-- Booking Status Distribution -->
                <div class="chart-card">
                    <h3><i data-lucide="pie-chart" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Booking Status Distribution</h3>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                
                <!-- Booking Types -->
                <div class="chart-card">
                    <h3><i data-lucide="repeat" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Booking Types</h3>
                    <div class="chart-container">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>
                
                <!-- Peak Booking Hours -->
                <div class="chart-card">
                    <h3><i data-lucide="clock" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Peak Booking Hours</h3>
                    <div class="chart-container">
                        <canvas id="peakHoursChart"></canvas>
                    </div>
                </div>
                
                <!-- Bookings by Day of Week -->
                <div class="chart-card">
                    <h3><i data-lucide="calendar" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Bookings by Day of Week</h3>
                    <div class="chart-container">
                        <canvas id="dayChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Room Utilization Table -->
            <div class="chart-card" style="margin: 20px 0;">
                <h3><i data-lucide="building" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Room Utilization Report</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Room Name</th>
                            <th>Type</th>
                            <th>Building</th>
                            <th>Total Bookings</th>
                            <th>Approved</th>
                            <th>Total Hours</th>
                            <th>Utilization</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $room_utilization->data_seek(0);
                        while ($room = $room_utilization->fetch_assoc()): 
                            $total_available_hours = 30 * 12; // 30 days * 12 hours/day (rough estimate)
                            $utilization = $total_available_hours > 0 ? 
                                         round(($room['total_hours'] / $total_available_hours) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($room['room_name']); ?></strong></td>
                            <td><?php echo ucwords(str_replace('_', ' ', $room['room_type'])); ?></td>
                            <td><?php echo htmlspecialchars($room['building']); ?></td>
                            <td><?php echo $room['total_bookings']; ?></td>
                            <td><?php echo $room['approved_bookings']; ?></td>
                            <td><?php echo $room['total_hours']; ?>h</td>
                            <td>
                                <div style="background: var(--bg); border-radius: 10px; overflow: hidden; height: 20px;">
                                    <div style="background: <?php echo $utilization > 70 ? 'var(--danger)' : ($utilization > 40 ? 'var(--warning)' : 'var(--success)'); ?>; 
                                               width: <?php echo min($utilization, 100); ?>%; height: 100%; border-radius: 10px;"></div>
                                </div>
                                <small style="color:var(--text-secondary);"><?php echo $utilization; ?>%</small>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Most Active Users -->
            <div class="chart-card" style="margin: 20px 0;">
                <h3><i data-lucide="users" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Most Active Users</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Total Bookings</th>
                            <th>Approved</th>
                            <th>Approval Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        while ($user = $active_users->fetch_assoc()): 
                            $approval_rate = $user['booking_count'] > 0 ? 
                                           round(($user['approved_count'] / $user['booking_count']) * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo $rank++; ?></strong></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                            <td><?php echo $user['booking_count']; ?></td>
                            <td><?php echo $user['approved_count']; ?></td>
                            <td>
                                <strong style="color: <?php echo $approval_rate > 80 ? 'var(--success)' : ($approval_rate > 50 ? 'var(--warning)' : 'var(--danger)'); ?>">
                                    <?php echo $approval_rate; ?>%
                                </strong>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Maintenance Summary -->
            <?php if (count($maintenance_stats) > 0): ?>
            <div class="chart-card" style="margin: 20px 0;">
                <h3><i data-lucide="wrench" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Maintenance Reports Summary</h3>
                <div class="card-container">
                    <?php foreach ($maintenance_stats as $stat): ?>
                    <div class="metric-card" style="border-left-color: 
                        <?php 
                        echo $stat['urgency'] === 'critical' ? 'var(--danger)' : 
                            ($stat['urgency'] === 'high' ? 'var(--warning)' : 
                            ($stat['urgency'] === 'medium' ? 'var(--info)' : 'var(--muted)')); 
                        ?>">
                        <div class="metric-value"><?php echo $stat['count']; ?></div>
                        <div class="metric-label"><?php echo ucfirst($stat['urgency']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        // Status Distribution Chart
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Pending', 'Rejected', 'Cancelled'],
                datasets: [{
                    data: [
                        <?php echo $overall_stats['approved']; ?>,
                        <?php echo $overall_stats['pending']; ?>,
                        <?php echo $overall_stats['rejected']; ?>,
                        <?php echo $overall_stats['cancelled']; ?>
                    ],
                    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444', '#94a3b8']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Booking Types Chart
        new Chart(document.getElementById('typeChart'), {
            type: 'pie',
            data: {
                labels: [<?php echo '"' . implode('","', array_column($booking_types, 'type')) . '"'; ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($booking_types, 'count')); ?>],
                    backgroundColor: ['#6366f1', '#8b5cf6']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
        
        // Peak Hours Chart
        new Chart(document.getElementById('peakHoursChart'), {
            type: 'bar',
            data: {
                labels: [<?php echo '"' . implode('","', array_map(function($h) { 
                    return $h['hour'] . ':00'; 
                }, $peak_data)) . '"'; ?>],
                datasets: [{
                    label: 'Bookings',
                    data: [<?php echo implode(',', array_column($peak_data, 'booking_count')); ?>],
                    backgroundColor: '#6366f1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
        
        // Day of Week Chart
        new Chart(document.getElementById('dayChart'), {
            type: 'bar',
            data: {
                labels: [<?php echo '"' . implode('","', array_column($day_data, 'day_name')) . '"'; ?>],
                datasets: [{
                    label: 'Bookings',
                    data: [<?php echo implode(',', array_column($day_data, 'booking_count')); ?>],
                    backgroundColor: '#8b5cf6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

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
