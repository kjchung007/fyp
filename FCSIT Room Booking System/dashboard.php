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

// Get available rooms (featured)
$rooms_query = "SELECT r.*, COUNT(rf.facility_id) as facility_count,
                GROUP_CONCAT(DISTINCT f.facility_name SEPARATOR ', ') as facilities
                FROM rooms r
                LEFT JOIN room_facilities rf ON r.room_id = rf.room_id
                LEFT JOIN facilities f ON rf.facility_id = f.facility_id
                WHERE r.status = 'available'
                GROUP BY r.room_id
                ORDER BY r.capacity DESC
                LIMIT 4";
$rooms_result = $conn->query($rooms_query);

// Get recent bookings
$bookings_query = "SELECT b.*, r.room_name, r.building
                   FROM bookings b
                   JOIN rooms r ON b.room_id = r.room_id
                   WHERE b.user_id = ?
                   ORDER BY b.created_at DESC
                   LIMIT 5";
$bookings_stmt = $conn->prepare($bookings_query);
$bookings_stmt->bind_param("i", $user_id);
$bookings_stmt->execute();
$bookings_result = $bookings_stmt->get_result();

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
    <title>User Dashboard - FCSIT Room Booking</title>
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
                <li class="active"><a href="dashboard.php"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a></li>
                <li><a href="browse_rooms.php"><i data-lucide="door-open"></i> <span>Browse Rooms</span></a></li>
                <li><a href="room_calendar.php"><i data-lucide="calendar-days"></i> <span>Room Calendar</span></a></li>
                <li><a href="my_bookings.php"><i data-lucide="clipboard-list"></i> <span>My Bookings</span>
                    <?php if ($stats['pending_count'] > 0): ?>
                    <span class="sidebar-badge"><?php echo $stats['pending_count']; ?></span>
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
                        <h2>Dashboard</h2>
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
                <h1>Welcome back, <?php echo explode(' ', $_SESSION['name'])[0]; ?>!</h1>
                <p>Here's your booking overview and quick actions.</p>
            </div>
            
            <?php
            if (isset($_SESSION['success'])) {
                echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
                unset($_SESSION['success']);
            }
            ?>
            
            <!-- Stats -->
            <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Total Bookings</div>
                            <div class="stat-value"><?php echo $stats['total_bookings']; ?></div>
                            <div class="stat-sub">All time</div>
                        </div>
                        <div class="stat-icon purple"><i data-lucide="calendar-check" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Pending Requests</div>
                            <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                            <div class="stat-sub">Awaiting approval</div>
                        </div>
                        <div class="stat-icon orange"><i data-lucide="clock" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div>
                            <div class="stat-label">Notifications</div>
                            <div class="stat-value"><?php echo $notif_count; ?></div>
                            <div class="stat-sub">Unread</div>
                        </div>
                        <div class="stat-icon blue"><i data-lucide="bell" style="width:22px;height:22px;"></i></div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="section-label">Quick Actions</div>
            <div class="quick-actions-grid" style="grid-template-columns: repeat(4, 1fr);">
                <a href="browse_rooms.php" class="qa-btn">
                    <div class="qa-icon purple"><i data-lucide="search" style="width:20px;height:20px;"></i></div>
                    Browse Rooms
                </a>
                <a href="my_bookings.php" class="qa-btn">
                    <div class="qa-icon blue"><i data-lucide="clipboard-list" style="width:20px;height:20px;"></i></div>
                    My Bookings
                </a>
                <a href="room_calendar.php" class="qa-btn">
                    <div class="qa-icon green"><i data-lucide="calendar-days" style="width:20px;height:20px;"></i></div>
                    Room Calendar
                </a>
                <a href="report_issue.php" class="qa-btn">
                    <div class="qa-icon red"><i data-lucide="alert-triangle" style="width:20px;height:20px;"></i></div>
                    Report Issue
                </a>
            </div>
            
            <!-- Featured Rooms -->
            <div class="section-label">Featured Rooms</div>
            <div class="room-grid">
                <?php while ($room = $rooms_result->fetch_assoc()): 
                    $type_icons = [
                        'tutorial_room' => 'book-open',
                        'lecture_hall' => 'presentation',
                        'lab' => 'monitor',
                        'meeting_room' => 'users',
                        'seminar_room' => 'mic'
                    ];
                    $icon = $type_icons[$room['room_type']] ?? 'door-open';
                ?>
                <div class="room-card">
                    <div class="room-card-image">
                        <?php if ($room['image_url']): ?>
                            <img src="<?php echo $room['image_url']; ?>" alt="<?php echo htmlspecialchars($room['room_name']); ?>">
                        <?php else: ?>
                            <i data-lucide="<?php echo $icon; ?>" style="width:48px;height:48px;color:var(--primary);opacity:0.3;"></i>
                        <?php endif; ?>
                        <span class="room-card-type"><?php echo ucwords(str_replace('_', ' ', $room['room_type'])); ?></span>
                    </div>
                    <div class="room-card-body">
                        <h3><?php echo htmlspecialchars($room['room_name']); ?></h3>
                        <div class="room-card-details">
                            <span><i data-lucide="users" style="width:13px;height:13px;"></i> <?php echo $room['capacity']; ?></span>
                            <span><i data-lucide="map-pin" style="width:13px;height:13px;"></i> <?php echo htmlspecialchars($room['building']); ?></span>
                        </div>
                        <?php if ($room['facilities']): ?>
                        <div class="room-card-facilities">
                            <?php foreach (explode(', ', $room['facilities']) as $f): ?>
                            <span class="facility-tag"><?php echo htmlspecialchars($f); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="room-card-actions">
                            <a href="book_room.php?id=<?php echo $room['room_id']; ?>" class="btn">Book Now</a>
                            <a href="room_details.php?id=<?php echo $room['room_id']; ?>" class="btn btn-outline">Details</a>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            
            <!-- Recent Bookings -->
            <div class="dash-card">
                <div class="dash-card-header">
                    <h3><span class="card-title-icon"><i data-lucide="history" style="width:16px;height:16px;color:var(--primary);"></i> Recent Bookings</span></h3>
                    <a href="my_bookings.php" class="view-all-link">View All <i data-lucide="arrow-right" style="width:14px;height:14px;"></i></a>
                </div>
                <div class="dash-card-body">
                    <?php if ($bookings_result->num_rows > 0): ?>
                    <div class="card-list">
                        <?php while ($booking = $bookings_result->fetch_assoc()): ?>
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
                                    </div>
                                </div>
                            </div>
                            <div class="card-row-right">
                                <span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo ucfirst($booking['status']); ?></span>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state" style="border:none;">
                        <i data-lucide="inbox" style="width:32px;height:32px;opacity:0.3;margin-bottom:8px;"></i>
                        <p>You haven't made any bookings yet.</p>
                        <a href="browse_rooms.php" class="btn btn-success" style="margin-top: 12px;">Browse Available Rooms</a>
                    </div>
                    <?php endif; ?>
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
