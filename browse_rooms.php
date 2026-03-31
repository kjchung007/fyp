<?php
require_once 'config.php';

if (!is_logged_in()) {
    header("Location: login.php");
    exit();
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

// Get filter parameters
$room_type = $_GET['room_type'] ?? '';
$min_capacity = $_GET['min_capacity'] ?? '';
$facility_filter = $_GET['facility'] ?? '';

// Build query
$query = "SELECT DISTINCT r.*, 
          GROUP_CONCAT(DISTINCT f.facility_name SEPARATOR ', ') as facilities
          FROM rooms r
          LEFT JOIN room_facilities rf ON r.room_id = rf.room_id
          LEFT JOIN facilities f ON rf.facility_id = f.facility_id
          WHERE r.status = 'available'";

if ($room_type) {
    $query .= " AND r.room_type = '" . sanitize_input($room_type) . "'";
}

if ($min_capacity) {
    $query .= " AND r.capacity >= " . intval($min_capacity);
}

if ($facility_filter) {
    $query .= " AND EXISTS (
        SELECT 1 FROM room_facilities rf2
        JOIN facilities f2 ON rf2.facility_id = f2.facility_id
        WHERE rf2.room_id = r.room_id
        AND f2.facility_name LIKE '%" . sanitize_input($facility_filter) . "%'
    )";
}

$query .= " GROUP BY r.room_id ORDER BY r.room_name";

$result = $conn->query($query);

// Get all facilities for filter
$facilities_query = "SELECT DISTINCT facility_name FROM facilities ORDER BY facility_name";
$facilities_result = $conn->query($facilities_query);

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

// Smart Recommendations
$pattern_query = "SELECT 
                  r.room_id,
                  r.room_name,
                  r.room_type,
                  r.capacity,
                  r.building,
                  r.image_url,
                  COUNT(*) as booking_count,
                  GROUP_CONCAT(DISTINCT DAYOFWEEK(b.booking_date)) as preferred_days,
                  AVG(HOUR(b.start_time)) as avg_start_hour,
                  AVG(TIMESTAMPDIFF(HOUR, b.start_time, b.end_time)) as avg_duration
                  FROM bookings b
                  JOIN rooms r ON b.room_id = r.room_id
                  WHERE b.user_id = ?
                  AND b.status = 'approved'
                  AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                  GROUP BY r.room_id
                  HAVING COUNT(*) >= 2
                  ORDER BY booking_count DESC
                  LIMIT 3";

$pattern_stmt = $conn->prepare($pattern_query);
$pattern_stmt->bind_param("i", $user_id);
$pattern_stmt->execute();
$recommendations = $pattern_stmt->get_result();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Rooms - FCSIT Room Booking</title>
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
                <li class="active"><a href="browse_rooms.php"><i data-lucide="door-open"></i> <span>Browse Rooms</span></a></li>
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
                        <h2>Browse Rooms</h2>
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
                <h1>Browse Available Rooms</h1>
                <p>Find and book the perfect room for your needs.</p>
            </div>
            
            <?php if ($recommendations->num_rows > 0): ?>
            <!-- Smart Recommendations -->
            <div class="recommend-banner">
                <h3><i data-lucide="sparkles" style="width:20px;height:20px;"></i> Smart Recommendations</h3>
                <p>Based on your booking history, you might be interested in these rooms</p>
            </div>
            
            <div class="room-grid" style="margin-bottom: 30px;">
                <?php while ($rec = $recommendations->fetch_assoc()): 
                    $start_hour = round($rec['avg_start_hour']);
                    $duration = round($rec['avg_duration']);
                    $end_hour = $start_hour + $duration;
                    $days = explode(',', $rec['preferred_days']);
                    $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    $preferred_days_str = implode(', ', array_map(function($d) use ($day_names) {
                        return $day_names[$d - 1];
                    }, $days));
                    $type_icons = [
                        'tutorial_room' => 'book-open',
                        'lecture_hall' => 'presentation',
                        'lab' => 'monitor',
                        'meeting_room' => 'users'
                    ];
                    $icon = $type_icons[$rec['room_type']] ?? 'door-open';
                ?>
                <div class="room-card recommend-card">
                    <span class="recommend-tag"><i data-lucide="star" style="width:12px;height:12px;"></i> Recommended</span>
                    <div class="room-card-image">
                        <?php if ($rec['image_url']): ?>
                            <img src="<?php echo htmlspecialchars($rec['image_url']); ?>" alt="<?php echo htmlspecialchars($rec['room_name']); ?>">
                        <?php else: ?>
                            <i data-lucide="<?php echo $icon; ?>" style="width:48px;height:48px;color:var(--primary);opacity:0.3;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="room-card-body">
                        <h3><?php echo htmlspecialchars($rec['room_name']); ?></h3>
                        <div class="room-card-details">
                            <span><i data-lucide="map-pin" style="width:13px;height:13px;"></i> <?php echo htmlspecialchars($rec['building']); ?></span>
                            <span><i data-lucide="users" style="width:13px;height:13px;"></i> <?php echo $rec['capacity']; ?></span>
                        </div>
                        <div class="pattern-box">
                            <strong>Your Pattern:</strong><br>
                            Booked <?php echo $rec['booking_count']; ?>x · Usually: <?php echo $preferred_days_str; ?> · <?php echo sprintf('%d:00 - %d:00', $start_hour, $end_hour); ?>
                        </div>
                        <div class="room-card-actions">
                            <a href="book_room.php?id=<?php echo $rec['room_id']; ?>" class="btn">Book Again</a>
                            <a href="room_details.php?id=<?php echo $rec['room_id']; ?>" class="btn btn-outline">Details</a>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            
            <div class="section-label">All Available Rooms</div>
            <?php endif; ?>
            
            <!-- Filter Section -->
            <div class="filter-sidebar">
                <h4><i data-lucide="filter" style="width:14px;height:14px;"></i> Filter Rooms</h4>
                <form method="GET" action="browse_rooms.php" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                        <label>Room Type</label>
                        <select name="room_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="tutorial_room" <?php echo $room_type === 'tutorial_room' ? 'selected' : ''; ?>>Tutorial Room</option>
                            <option value="lecture_hall" <?php echo $room_type === 'lecture_hall' ? 'selected' : ''; ?>>Lecture Hall</option>
                            <option value="lab" <?php echo $room_type === 'lab' ? 'selected' : ''; ?>>Computer Lab</option>
                            <option value="meeting_room" <?php echo $room_type === 'meeting_room' ? 'selected' : ''; ?>>Meeting Room</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="width:140px;margin-bottom:0;">
                        <label>Min Capacity</label>
                        <input type="number" name="min_capacity" class="form-control" 
                               value="<?php echo htmlspecialchars($min_capacity); ?>" 
                               placeholder="e.g. 30">
                    </div>
                    
                    <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                        <label>Required Facility</label>
                        <select name="facility" class="form-control">
                            <option value="">Any Facility</option>
                            <?php while ($fac = $facilities_result->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($fac['facility_name']); ?>" 
                                    <?php echo $facility_filter === $fac['facility_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fac['facility_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn">Apply</button>
                    <a href="browse_rooms.php" class="btn btn-outline">Clear</a>
                </form>
            </div>
            
            <!-- Rooms Grid -->
            <div class="section-label">Available Rooms (<?php echo $result->num_rows; ?> found)</div>
            <div class="room-grid">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($room = $result->fetch_assoc()): 
                        $type_icons = [
                            'tutorial_room' => 'book-open',
                            'lecture_hall' => 'presentation',
                            'lab' => 'monitor',
                            'meeting_room' => 'users'
                        ];
                        $icon = $type_icons[$room['room_type']] ?? 'door-open';
                    ?>
                    <div class="room-card">
                        <div class="room-card-image">
                            <?php if ($room['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($room['image_url']); ?>" alt="<?php echo htmlspecialchars($room['room_name']); ?>">
                            <?php else: ?>
                                <i data-lucide="<?php echo $icon; ?>" style="width:48px;height:48px;color:var(--primary);opacity:0.3;"></i>
                            <?php endif; ?>
                            <span class="room-card-type"><?php echo ucwords(str_replace('_', ' ', $room['room_type'])); ?></span>
                        </div>
                        <div class="room-card-body">
                            <h3><?php echo htmlspecialchars($room['room_name']); ?></h3>
                            <div class="room-card-details">
                                <span><i data-lucide="users" style="width:13px;height:13px;"></i> <?php echo $room['capacity']; ?> people</span>
                                <span><i data-lucide="map-pin" style="width:13px;height:13px;"></i> <?php echo htmlspecialchars($room['building']); ?>, Floor <?php echo $room['floor']; ?></span>
                            </div>
                            <?php if ($room['facilities']): ?>
                            <div class="room-card-facilities">
                                <?php foreach (explode(', ', $room['facilities']) as $f): ?>
                                <span class="facility-tag"><?php echo htmlspecialchars($f); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="room-card-actions">
                                <a href="room_details.php?id=<?php echo $room['room_id']; ?>" class="btn btn-outline">Details</a>
                                <a href="book_room.php?id=<?php echo $room['room_id']; ?>" class="btn btn-success">Book Now</a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1;">
                        <div class="empty-state">
                            <i data-lucide="search-x" style="width:32px;height:32px;opacity:0.3;margin-bottom:8px;"></i>
                            <p>No rooms found matching your criteria.</p>
                            <a href="browse_rooms.php" class="btn" style="margin-top: 12px;">View All Rooms</a>
                        </div>
                    </div>
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
