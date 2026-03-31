<?php
require_once 'config.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];

// Get booking statistics
$stats_query = "SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
                FROM bookings 
                WHERE user_id = ?";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Get all rooms for dropdown
$rooms_query = "SELECT room_id, room_name, building, floor FROM rooms ORDER BY room_name";
$rooms_result = $conn->query($rooms_query);

// Get facilities for dropdown
$facilities_query = "SELECT facility_id, facility_name FROM facilities ORDER BY facility_name";
$facilities_result = $conn->query($facilities_query);

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
    <title>Report Issue - FCSIT Room Booking</title>
    <link rel="stylesheet" href="style.css">
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
                <li><a href="my_bookings.php"><i data-lucide="clipboard-list"></i> <span>My Bookings</span>
                    <?php if ($stats['pending_count'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $stats['pending_count']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="active"><a href="report_issue.php"><i data-lucide="alert-triangle"></i> <span>Report Issue</span></a></li>
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
                        <h2>Report Issue</h2>
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

            <!-- Content -->
            <div class="dash-header">
                <h1>Report Facility Issue</h1>
                <p>Found a problem? Let us know so our maintenance team can fix it.</p>
            </div>
            
            <div style="max-width: 800px;">
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
                
                <div class="info-box info">
                    <strong><i data-lucide="info" style="width:14px;height:14px;"></i> Help us maintain our facilities!</strong><br>
                    If you notice any equipment malfunction, damage, or cleanliness issues in any room, 
                    please report it here. Our maintenance team will be notified immediately.
                </div>
                
                <div class="booking-form-card">
                    <h3><i data-lucide="alert-triangle" style="width:16px;height:16px;color:var(--warning);"></i> Maintenance Report Form</h3>
                    
                    <form action="actions/submit_report.php" method="POST">
                        <div class="form-group">
                            <label for="room_id">Room *</label>
                            <select id="room_id" name="room_id" class="form-control" required>
                                <option value="">-- Select Room --</option>
                                <?php while ($room = $rooms_result->fetch_assoc()): ?>
                                <option value="<?php echo $room['room_id']; ?>">
                                    <?php echo htmlspecialchars($room['room_name']); ?> 
                                    (<?php echo htmlspecialchars($room['building']); ?>, Floor <?php echo $room['floor']; ?>)
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="facility_id">Related Facility (Optional)</label>
                            <select id="facility_id" name="facility_id" class="form-control">
                                <option value="">-- General Room Issue --</option>
                                <?php while ($facility = $facilities_result->fetch_assoc()): ?>
                                <option value="<?php echo $facility['facility_id']; ?>">
                                    <?php echo htmlspecialchars($facility['facility_name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="issue_type">Issue Type *</label>
                                <select id="issue_type" name="issue_type" class="form-control" required>
                                    <option value="">-- Select Issue Type --</option>
                                    <option value="equipment_fault">Equipment Fault/Malfunction</option>
                                    <option value="furniture_damage">Furniture Damage</option>
                                    <option value="cleanliness">Cleanliness Issue</option>
                                    <option value="other">Other Issue</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="urgency">Urgency Level *</label>
                                <select id="urgency" name="urgency" class="form-control" required>
                                    <option value="low">🟢 Low - Can wait</option>
                                    <option value="medium" selected>🟡 Medium - Address soon</option>
                                    <option value="high">🟠 High - Affects usability</option>
                                    <option value="critical">🔴 Critical - Room unusable</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Issue Description *</label>
                            <textarea id="description" name="description" class="form-control" required 
                                      rows="5" placeholder="Please provide detailed description of the issue, including:
- What is broken/damaged?
- When did you notice it?
- Is the room still usable?
- Any other relevant details"></textarea>
                        </div>
                        
                        <div class="info-box warning">
                            <strong><i data-lucide="alert-circle" style="width:14px;height:14px;"></i> Note:</strong> For critical safety issues (electrical hazards, structural damage, etc.), 
                            please also contact the faculty administration immediately.
                        </div>
                        
                        <div style="display:flex;gap:10px;margin-top:20px;">
                            <button type="submit" class="btn btn-success" style="padding:10px 28px;">
                                <i data-lucide="send" style="width:16px;height:16px;"></i> Submit Report
                            </button>
                            <a href="dashboard.php" class="btn btn-outline" style="padding:10px 28px;">Cancel</a>
                        </div>
                    </form>
                </div>
                
                <!-- Recent Reports -->
                <div class="dash-card" style="margin-top:24px;">
                    <div class="dash-card-header">
                        <h3><span class="card-title-icon"><i data-lucide="history" style="width:16px;height:16px;color:var(--primary);"></i> My Recent Reports</span></h3>
                    </div>
                    <div class="dash-card-body">
                        <?php
                        $reports_query = "SELECT mr.*, r.room_name, f.facility_name
                                          FROM maintenance_reports mr
                                          JOIN rooms r ON mr.room_id = r.room_id
                                          LEFT JOIN facilities f ON mr.facility_id = f.facility_id
                                          WHERE mr.reported_by = ?
                                          ORDER BY mr.reported_at DESC
                                          LIMIT 5";
                        $reports_stmt = $conn->prepare($reports_query);
                        $reports_stmt->bind_param("i", $user_id);
                        $reports_stmt->execute();
                        $reports_result = $reports_stmt->get_result();
                        ?>
                        
                        <?php if ($reports_result->num_rows > 0): ?>
                        <div class="card-list">
                            <?php while ($report = $reports_result->fetch_assoc()): ?>
                            <div class="card-row">
                                <div class="card-row-left">
                                    <div class="card-row-icon <?php echo $report['urgency'] === 'critical' ? 'red' : ($report['urgency'] === 'high' ? 'orange' : ''); ?>">
                                        <i data-lucide="wrench" style="width:18px;height:18px;"></i>
                                    </div>
                                    <div class="card-row-info">
                                        <h3><?php echo htmlspecialchars($report['room_name']); ?></h3>
                                        <div class="card-row-meta">
                                            <span><?php echo ucwords(str_replace('_', ' ', $report['issue_type'])); ?></span>
                                            <?php if ($report['facility_name']): ?>
                                            <span><?php echo htmlspecialchars($report['facility_name']); ?></span>
                                            <?php endif; ?>
                                            <span><?php echo date('M j, Y', strtotime($report['reported_at'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-row-right">
                                    <span class="badge badge-<?php echo $report['urgency']; ?>"><?php echo ucfirst($report['urgency']); ?></span>
                                    <span class="status-badge status-<?php echo $report['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?></span>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state" style="border:none;padding:24px;">
                            <i data-lucide="inbox" style="width:28px;height:28px;opacity:0.3;margin-bottom:8px;"></i>
                            <p>No reports submitted yet.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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
