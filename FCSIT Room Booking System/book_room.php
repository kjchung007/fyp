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

$room_id = intval($_GET['id'] ?? 0);

if ($room_id === 0) {
    header("Location: browse_rooms.php");
    exit();
}

// Get room details
$query = "SELECT * FROM rooms WHERE room_id = ? AND status = 'available'";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $room_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

if (!$room) {
    $_SESSION['error'] = "Room not found or unavailable.";
    header("Location: browse_rooms.php");
    exit();
}

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

// Check if user is lecturer or admin (can book recurring)
$can_book_recurring = check_role(['lecturer', 'admin', 'super_admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($room['room_name']); ?> - FCSIT</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .preview-date {
            padding: 8px 12px;
            margin: 5px 0;
            background: var(--bg);
            border-left: 3px solid var(--info);
            border-radius: 4px;
            font-size: 0.88em;
        }
        .conflict-warning {
            background: var(--warning-bg);
            border-left-color: var(--warning);
            color: #92400e;
        }
        .conflict-error {
            background: var(--danger-bg);
            border-left-color: var(--danger);
            color: #991b1b;
        }
    </style>
    <script>
        let recurringEnabled = false;
        
        function toggleRecurring() {
            recurringEnabled = document.getElementById('enable_recurring').checked;
            document.getElementById('recurring-options').style.display = recurringEnabled ? 'block' : 'none';
            
            if (recurringEnabled) {
                updateRecurringPreview();
            }
        }
        
        function updateRecurringPreview() {
            const startDate = document.getElementById('booking_date').value;
            const pattern = document.getElementById('recurrence_pattern').value;
            const occurrences = parseInt(document.getElementById('occurrences').value) || 1;
            
            if (!startDate) {
                return;
            }
            
            const dates = generateRecurringDates(startDate, pattern, occurrences);
            checkMultipleAvailability(dates);
        }
        
        function generateRecurringDates(startDate, pattern, occurrences) {
            const dates = [startDate];
            const start = new Date(startDate);
            
            for (let i = 1; i < occurrences; i++) {
                const nextDate = new Date(start);
                
                switch(pattern) {
                    case 'weekly':
                        nextDate.setDate(start.getDate() + (i * 7));
                        break;
                    case 'biweekly':
                        nextDate.setDate(start.getDate() + (i * 14));
                        break;
                    case 'monthly':
                        nextDate.setMonth(start.getMonth() + i);
                        break;
                }
                
                dates.push(nextDate.toISOString().split('T')[0]);
            }
            
            return dates;
        }
        
        async function checkMultipleAvailability(dates) {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const roomId = <?php echo $room_id; ?>;
            
            if (!startTime || !endTime) {
                return;
            }
            
            const previewDiv = document.getElementById('recurring-preview');
            previewDiv.innerHTML = '<p style="color:var(--text-secondary);">Checking availability...</p>';
            
            let html = '<h4 style="margin-bottom:8px;font-size:0.9em;">Booking Preview (' + dates.length + ' dates):</h4>';
            let allAvailable = true;
            let conflictCount = 0;
            
            for (const date of dates) {
                try {
                    const response = await fetch(`actions/check_availability.php?room_id=${roomId}&date=${date}&start_time=${startTime}&end_time=${endTime}`);
                    const result = await response.json();
                    
                    const dateObj = new Date(date);
                    const formattedDate = dateObj.toLocaleDateString('en-US', { 
                        weekday: 'long', 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric' 
                    });
                    
                    if (result.available) {
                        html += `<div class="preview-date">✓ ${formattedDate} - Available</div>`;
                    } else {
                        html += `<div class="preview-date conflict-error">✗ ${formattedDate} - CONFLICT: ${result.message}</div>`;
                        allAvailable = false;
                        conflictCount++;
                    }
                } catch (error) {
                    html += `<div class="preview-date conflict-warning">⚠ ${date} - Error checking</div>`;
                }
            }
            
            if (!allAvailable) {
                html += `<div class="info-box warning" style="margin-top:10px;">
                    <strong>⚠ Warning:</strong> ${conflictCount} date(s) have conflicts. These will be skipped during booking.
                </div>`;
            } else {
                html += `<div class="info-box success" style="margin-top:10px;">
                    <strong>✓ All dates available!</strong> You can proceed with booking.
                </div>`;
            }
            
            previewDiv.innerHTML = html;
        }
        
        function checkAvailability() {
            const date = document.getElementById('booking_date').value;
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const roomId = <?php echo $room_id; ?>;
            
            if (!date || !startTime || !endTime) {
                return;
            }
            
            if (endTime <= startTime) {
                alert('End time must be after start time');
                return;
            }
            
            if (recurringEnabled) {
                updateRecurringPreview();
                return;
            }
            
            fetch(`actions/check_availability.php?room_id=${roomId}&date=${date}&start_time=${startTime}&end_time=${endTime}`)
                .then(response => response.json())
                .then(data => {
                    const resultDiv = document.getElementById('availability-result');
                    if (data.available) {
                        resultDiv.innerHTML = '<div class="alert alert-success">✓ Time slot is available!</div>';
                    } else {
                        resultDiv.innerHTML = '<div class="alert alert-danger">✗ ' + data.message + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
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
                        <h2>Book Room</h2>
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
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                <a href="browse_rooms.php" class="btn btn-outline"><i data-lucide="arrow-left" style="width:14px;height:14px;"></i> Back to Browse Rooms</a>
            </div>
            
            <div class="dash-header">
                <h1>Book <?php echo htmlspecialchars($room['room_name']); ?></h1>
                <p><?php echo ucwords(str_replace('_', ' ', $room['room_type'])); ?> · Capacity: <?php echo $room['capacity']; ?> · <?php echo htmlspecialchars($room['building']); ?>, Floor <?php echo $room['floor']; ?></p>
            </div>
            
            <?php
            if (isset($_SESSION['error'])) {
                echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
                unset($_SESSION['error']);
            }
            if (isset($_SESSION['success'])) {
                echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
                unset($_SESSION['success']);
            }
            ?>

            <?php if ($room['image_url']): ?>
            <div style="max-width: 900px; margin: 0 auto 20px auto; text-align: center;">
                <img src="<?php echo htmlspecialchars($room['image_url']); ?>" 
                     alt="<?php echo htmlspecialchars($room['room_name']); ?>"
                     style="max-width: 100%; max-height: 300px; border-radius: var(--radius); box-shadow: var(--shadow-md); object-fit: cover;">
            </div>
            <?php endif; ?>
            
            <div style="max-width: 900px; margin: 0 auto;">
                <div class="room-info-grid">
                    <div class="room-info-panel">
                        <h4><i data-lucide="info" style="width:14px;height:14px;"></i> Room Information</h4>
                        <p><strong>Type:</strong> <?php echo ucwords(str_replace('_', ' ', $room['room_type'])); ?></p>
                        <p><strong>Capacity:</strong> <?php echo $room['capacity']; ?> people</p>
                        <p><strong>Building:</strong> <?php echo htmlspecialchars($room['building']); ?></p>
                        <p><strong>Floor:</strong> <?php echo $room['floor']; ?></p>
                    </div>
                    <div class="room-info-panel">
                        <h4><i data-lucide="book-open" style="width:14px;height:14px;"></i> Booking Guidelines</h4>
                        <ul>
                            <li>Bookings require admin approval</li>
                            <li>Submit at least 1 day in advance</li>
                            <li>Cancel if plans change</li>
                            <?php if ($can_book_recurring): ?>
                            <li><strong>✨ You can book recurring slots!</strong></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                
                <form action="actions/submit_booking.php" method="POST">
                    <input type="hidden" name="room_id" value="<?php echo $room_id; ?>">
                    
                    <div class="booking-form-card">
                        <h3><i data-lucide="calendar-plus" style="width:16px;height:16px;color:var(--primary);"></i> Booking Details</h3>
                        
                        <div class="form-group">
                            <label for="booking_date">Date *</label>
                            <input type="date" id="booking_date" name="booking_date" class="form-control" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                                   required onchange="checkAvailability()">
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="start_time">Start Time *</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" 
                                       required onchange="checkAvailability()">
                            </div>
                            
                            <div class="form-group">
                                <label for="end_time">End Time *</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" 
                                       required onchange="checkAvailability()">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="purpose">Purpose *</label>
                            <textarea id="purpose" name="purpose" class="form-control" rows="3" 
                                      required placeholder="e.g., CS101 Tutorial Class, Department Meeting, Workshop"></textarea>
                        </div>
                        
                        <div id="availability-result"></div>
                    </div>
                    
                    <?php if ($can_book_recurring): ?>
                    <div class="recurring-section-modern">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                            <input type="checkbox" id="enable_recurring" name="enable_recurring" 
                                   onchange="toggleRecurring()" style="width: 18px; height: 18px; cursor:pointer;">
                            <label for="enable_recurring" style="margin: 0; font-weight: 600; cursor: pointer; font-size:0.95em;">
                                <i data-lucide="repeat" style="width:16px;height:16px;"></i> Book Recurring (Multiple Weeks/Months)
                            </label>
                        </div>
                        
                        <p style="color: #1e40af; font-size:0.85em; margin-bottom: 12px;">
                            Perfect for weekly classes or regular meetings! Book multiple dates at once.
                        </p>
                        
                        <div id="recurring-options" style="display: none;">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="recurrence_pattern">Repeat Pattern</label>
                                    <select id="recurrence_pattern" name="recurrence_pattern" class="form-control" 
                                            onchange="updateRecurringPreview()">
                                        <option value="weekly">Every Week (Same Day)</option>
                                        <option value="biweekly">Every 2 Weeks</option>
                                        <option value="monthly">Every Month (Same Date)</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="occurrences">Number of Occurrences</label>
                                    <input type="number" id="occurrences" name="occurrences" class="form-control" 
                                           min="1" max="20" value="4" onchange="updateRecurringPreview()">
                                    <small style="color: var(--text-light); font-size:0.8em;">Max 20 bookings at once</small>
                                </div>
                            </div>
                            
                            <div id="recurring-preview" style="background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:15px;margin-top:12px;max-height:300px;overflow-y:auto;">
                                <p style="color: var(--text-light); font-size:0.88em;">Select date and time to preview recurring bookings...</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div style="text-align: center; margin-top: 20px; display:flex; justify-content:center; gap:12px;">
                        <button type="submit" class="btn btn-success" style="padding: 12px 36px;">
                            <i data-lucide="send" style="width:16px;height:16px;"></i> Submit Booking Request
                        </button>
                        <a href="browse_rooms.php" class="btn btn-outline" style="padding: 12px 36px;">Cancel</a>
                    </div>
                </form>
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
