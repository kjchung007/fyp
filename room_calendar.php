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

// Get selected date (default to current month)
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_month = date('Y-m', strtotime($current_date));
$month_start = date('Y-m-01', strtotime($current_date));
$month_end = date('Y-m-t', strtotime($current_date));

// Get selected room filter
$room_filter = isset($_GET['room']) ? intval($_GET['room']) : 0;

// Get all rooms for filter
$rooms_query = "SELECT room_id, room_name, room_type, building FROM rooms WHERE status = 'available' ORDER BY room_name";
$rooms_result = $conn->query($rooms_query);

// Build booking query
$booking_query = "SELECT b.*, r.room_name, r.room_type, r.building, u.name as booked_by, u.role
                  FROM bookings b
                  JOIN rooms r ON b.room_id = r.room_id
                  JOIN users u ON b.user_id = u.user_id
                  WHERE b.booking_date BETWEEN ? AND ?
                  AND b.status IN ('approved', 'pending')";

if ($room_filter > 0) {
    $booking_query .= " AND b.room_id = ?";
}

$booking_query .= " ORDER BY b.booking_date, b.start_time";

$stmt = $conn->prepare($booking_query);
if ($room_filter > 0) {
    $stmt->bind_param("ssi", $month_start, $month_end, $room_filter);
} else {
    $stmt->bind_param("ss", $month_start, $month_end);
}
$stmt->execute();
$bookings_result = $stmt->get_result();

// Organize bookings by date
$bookings_by_date = [];
while ($booking = $bookings_result->fetch_assoc()) {
    $date = $booking['booking_date'];
    if (!isset($bookings_by_date[$date])) {
        $bookings_by_date[$date] = [];
    }
    $bookings_by_date[$date][] = $booking;
}

// Get calendar days
$first_day = new DateTime($month_start);
$last_day = new DateTime($month_end);
$days_in_month = $last_day->format('d');
$first_day_of_week = $first_day->format('w');

// Calculate previous and next month
$prev_month = date('Y-m-d', strtotime($current_date . ' -1 month'));
$next_month = date('Y-m-d', strtotime($current_date . ' +1 month'));

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
    <title>Room Calendar - FCSIT Booking</title>
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
                <li class="active"><a href="room_calendar.php"><i data-lucide="calendar-days"></i> <span>Room Calendar</span></a></li>
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
                        <h2>Room Calendar</h2>
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

            <div class="calendar-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <h1><i data-lucide="calendar-days" style="width:28px;height:28px;"></i> Room Calendar</h1>
                    <p>View all room bookings at a glance</p>
                </div>
                <div class="month-navigation">
                    <button onclick="window.location.href='?date=<?php echo $prev_month; ?>&room=<?php echo $room_filter; ?>'">
                        <i data-lucide="chevron-left" style="width:16px;height:16px;"></i> Previous
                    </button>
                    <h2 style="margin: 0; min-width: 180px; text-align: center; font-size:1.2em;">
                        <?php echo date('F Y', strtotime($current_date)); ?>
                    </h2>
                    <button onclick="window.location.href='?date=<?php echo $next_month; ?>&room=<?php echo $room_filter; ?>'">
                        Next <i data-lucide="chevron-right" style="width:16px;height:16px;"></i>
                    </button>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-sidebar">
                <form method="GET" action="room_calendar.php" style="display:flex;gap:12px;align-items:end;">
                    <input type="hidden" name="date" value="<?php echo $current_date; ?>">
                    <div class="form-group" style="flex:1;margin-bottom:0;">
                        <label>Filter by Room</label>
                        <select name="room" class="form-control">
                            <option value="0">All Rooms</option>
                            <?php 
                            $rooms_result->data_seek(0);
                            while ($room = $rooms_result->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $room['room_id']; ?>" 
                                    <?php echo $room_filter === $room['room_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($room['room_name']) . ' - ' . htmlspecialchars($room['building']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn">Apply</button>
                    <a href="room_calendar.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline">Today</a>
                </form>
            </div>
            
            <!-- Calendar Grid -->
            <div class="calendar-grid">
                <div class="calendar-day-header">Sun</div>
                <div class="calendar-day-header">Mon</div>
                <div class="calendar-day-header">Tue</div>
                <div class="calendar-day-header">Wed</div>
                <div class="calendar-day-header">Thu</div>
                <div class="calendar-day-header">Fri</div>
                <div class="calendar-day-header">Sat</div>
                
                <?php for ($i = 0; $i < $first_day_of_week; $i++): ?>
                <div class="calendar-day empty"></div>
                <?php endfor; ?>
                
                <?php for ($day = 1; $day <= $days_in_month; $day++): 
                    $current_day_date = sprintf('%s-%02d', $selected_month, $day);
                    $is_today = $current_day_date === date('Y-m-d');
                    $day_bookings = $bookings_by_date[$current_day_date] ?? [];
                    $has_bookings = count($day_bookings) > 0;
                ?>
                <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?> <?php echo $has_bookings ? 'has-bookings' : ''; ?>"
                     onclick="showDayDetails('<?php echo $current_day_date; ?>')">
                    <div class="day-number"><?php echo $day; ?></div>
                    <?php 
                    $shown = 0;
                    foreach ($day_bookings as $booking): 
                        if ($shown >= 3) {
                            echo '<div class="booking-indicator" style="background: var(--muted-bg); color: var(--muted);">+' . (count($day_bookings) - 3) . ' more</div>';
                            break;
                        }
                    ?>
                    <div class="booking-indicator booking-<?php echo $booking['status']; ?>"
                         title="<?php echo htmlspecialchars($booking['room_name']) . ' - ' . 
                                      date('g:i A', strtotime($booking['start_time'])); ?>">
                        <?php echo date('g:iA', strtotime($booking['start_time'])) . ' ' . 
                                   htmlspecialchars(substr($booking['room_name'], 0, 15)); ?>
                    </div>
                    <?php 
                        $shown++;
                    endforeach; 
                    ?>
                </div>
                <?php endfor; ?>
            </div>
            
            <!-- Legend -->
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--primary-bg); border: 2px solid var(--primary);"></div>
                    <span>Today</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--success-bg); border-left: 3px solid var(--success);"></div>
                    <span>Approved</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--warning-bg); border-left: 3px solid var(--warning);"></div>
                    <span>Pending</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--warning-bg);"></div>
                    <span>Has Bookings</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal for day details -->
    <div id="dayModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Bookings for Date</h2>
                <button class="close-modal" onclick="closeModal()">×</button>
            </div>
            <div id="modalBody"></div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        const bookingsData = <?php echo json_encode($bookings_by_date); ?>;
        
        function showDayDetails(date) {
            const modal = document.getElementById('dayModal');
            const title = document.getElementById('modalTitle');
            const body = document.getElementById('modalBody');
            
            const dateObj = new Date(date + 'T00:00:00');
            const formattedDate = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            title.textContent = 'Bookings for ' + formattedDate;
            
            const dayBookings = bookingsData[date] || [];
            
            if (dayBookings.length === 0) {
                body.innerHTML = `
                    <div class="empty-state" style="border:none;">
                        <p>No bookings for this day</p>
                        <a href="browse_rooms.php" class="btn" style="margin-top: 12px;">Browse Rooms to Book</a>
                    </div>
                `;
            } else {
                let html = '';
                dayBookings.forEach(booking => {
                    const statusClass = booking.status === 'approved' ? '' : 'pending';
                    const statusBadge = booking.status === 'approved' 
                        ? '<span class="status-badge status-approved">Approved</span>'
                        : '<span class="status-badge status-pending">Pending</span>';
                    
                    html += `
                        <div class="booking-card ${statusClass}">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <h3 style="margin: 0; font-size:1em; font-weight:600;">
                                    ${escapeHtml(booking.room_name)}
                                </h3>
                                ${statusBadge}
                            </div>
                            <div class="booking-info">
                                <div class="info-item">
                                    <span class="info-icon">🏢</span>
                                    <div>
                                        <small style="color: var(--text-light);">Building</small><br>
                                        <strong>${escapeHtml(booking.building)}</strong>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <span class="info-icon">⏰</span>
                                    <div>
                                        <small style="color: var(--text-light);">Time</small><br>
                                        <strong>${formatTime(booking.start_time)} - ${formatTime(booking.end_time)}</strong>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <span class="info-icon">👤</span>
                                    <div>
                                        <small style="color: var(--text-light);">Booked By</small><br>
                                        <strong>${escapeHtml(booking.booked_by)}</strong>
                                        <small style="color: var(--text-light);">(${booking.role})</small>
                                    </div>
                                </div>
                                <div class="info-item">
                                    <span class="info-icon">📝</span>
                                    <div>
                                        <small style="color: var(--text-light);">Purpose</small><br>
                                        <strong>${escapeHtml(booking.purpose.substring(0, 50))}${booking.purpose.length > 50 ? '...' : ''}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                body.innerHTML = html;
            }
            
            modal.classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('dayModal').classList.remove('active');
        }
        
        function formatTime(timeStr) {
            const time = new Date('2000-01-01 ' + timeStr);
            return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        document.getElementById('dayModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
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
