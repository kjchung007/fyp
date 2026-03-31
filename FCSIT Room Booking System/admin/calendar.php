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

// Get selected date and view type
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$view_type = isset($_GET['view']) ? $_GET['view'] : 'bookings'; // bookings or reports
$selected_month = date('Y-m', strtotime($current_date));
$month_start = date('Y-m-01', strtotime($current_date));
$month_end = date('Y-m-t', strtotime($current_date));

// Get room filter
$room_filter = isset($_GET['room']) ? intval($_GET['room']) : 0;

// Get all rooms
$rooms_query = "SELECT room_id, room_name, building FROM rooms ORDER BY room_name";
$rooms_result = $conn->query($rooms_query);

// Fetch bookings
$booking_query = "SELECT b.*, r.room_name, r.room_type, r.building, u.name as booked_by, u.role, u.email
                  FROM bookings b
                  JOIN rooms r ON b.room_id = r.room_id
                  JOIN users u ON b.user_id = u.user_id
                  WHERE b.booking_date BETWEEN ? AND ?";

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

$bookings_by_date = [];
while ($booking = $bookings_result->fetch_assoc()) {
    $date = $booking['booking_date'];
    if (!isset($bookings_by_date[$date])) {
        $bookings_by_date[$date] = [];
    }
    $bookings_by_date[$date][] = $booking;
}

// Fetch maintenance reports for the month
$reports_query = "SELECT mr.*, r.room_name, r.building, u.name as reporter_name, u.role as reporter_role,
                  DATE(mr.reported_at) as report_date
                  FROM maintenance_reports mr
                  JOIN rooms r ON mr.room_id = r.room_id
                  JOIN users u ON mr.reported_by = u.user_id
                  WHERE DATE(mr.reported_at) BETWEEN ? AND ?";

if ($room_filter > 0) {
    $reports_query .= " AND mr.room_id = ?";
}

$reports_query .= " ORDER BY mr.reported_at";

$rep_stmt = $conn->prepare($reports_query);
if ($room_filter > 0) {
    $rep_stmt->bind_param("ssi", $month_start, $month_end, $room_filter);
} else {
    $rep_stmt->bind_param("ss", $month_start, $month_end);
}
$rep_stmt->execute();
$reports_result = $rep_stmt->get_result();

$reports_by_date = [];
while ($report = $reports_result->fetch_assoc()) {
    $date = $report['report_date'];
    if (!isset($reports_by_date[$date])) {
        $reports_by_date[$date] = [];
    }
    $reports_by_date[$date][] = $report;
}

// Calendar calculations
$first_day = new DateTime($month_start);
$last_day = new DateTime($month_end);
$days_in_month = $last_day->format('d');
$first_day_of_week = $first_day->format('w');

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
    <title>Admin Calendar - FCSIT Booking</title>
    <link rel="stylesheet" href="../style.css">
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
                <li class="active"><a href="calendar.php"><i data-lucide="calendar-days"></i> <span>Calendar View</span></a></li>
                <li><a href="analytics.php"><i data-lucide="bar-chart-3"></i> <span>Analytics</span></a></li>
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
                        <h2>Calendar View</h2>
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
            <div class="calendar-header">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <h1><i data-lucide="calendar-days" style="width:28px;height:28px;vertical-align:middle;margin-right:8px;"></i> Admin Calendar</h1>
                        <p>Comprehensive view of bookings and maintenance reports</p>
                        <div class="view-toggle">
                            <a href="?date=<?php echo $current_date; ?>&view=bookings&room=<?php echo $room_filter; ?>" 
                               class="view-btn <?php echo $view_type === 'bookings' ? 'active' : ''; ?>">
                                <i data-lucide="calendar" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i> Bookings
                            </a>
                            <a href="?date=<?php echo $current_date; ?>&view=reports&room=<?php echo $room_filter; ?>" 
                               class="view-btn <?php echo $view_type === 'reports' ? 'active' : ''; ?>">
                                <i data-lucide="wrench" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i> Maintenance
                            </a>
                            <a href="?date=<?php echo $current_date; ?>&view=combined&room=<?php echo $room_filter; ?>" 
                               class="view-btn <?php echo $view_type === 'combined' ? 'active' : ''; ?>">
                                <i data-lucide="layers" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i> Combined
                            </a>
                        </div>
                    </div>
                    <div class="month-navigation">
                        <button onclick="window.location.href='?date=<?php echo $prev_month; ?>&view=<?php echo $view_type; ?>&room=<?php echo $room_filter; ?>'">
                            <i data-lucide="chevron-left" style="width:16px;height:16px;vertical-align:middle;"></i> Prev
                        </button>
                        <h2 style="margin: 0; min-width: 180px; text-align: center; font-size: 1.2em;">
                            <?php echo date('F Y', strtotime($current_date)); ?>
                        </h2>
                        <button onclick="window.location.href='?date=<?php echo $next_month; ?>&view=<?php echo $view_type; ?>&room=<?php echo $room_filter; ?>'">
                            Next <i data-lucide="chevron-right" style="width:16px;height:16px;vertical-align:middle;"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="card-container">
                <div class="stat-card-modern">
                    <div class="stat-icon blue"><i data-lucide="calendar" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo count(array_reduce($bookings_by_date, 'array_merge', [])); ?></div>
                        <div class="stat-label">Total Bookings</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon red"><i data-lucide="alert-triangle" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value">
                            <?php 
                            $critical_count = 0;
                            foreach ($reports_by_date as $reports) {
                                foreach ($reports as $report) {
                                    if ($report['urgency'] === 'critical') $critical_count++;
                                }
                            }
                            echo $critical_count;
                            ?>
                        </div>
                        <div class="stat-label">Critical Reports</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon orange"><i data-lucide="wrench" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo count(array_reduce($reports_by_date, 'array_merge', [])); ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green"><i data-lucide="check-circle-2" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value">
                            <?php
                            $approved_count = 0;
                            foreach ($bookings_by_date as $bookings) {
                                foreach ($bookings as $booking) {
                                    if ($booking['status'] === 'approved') $approved_count++;
                                }
                            }
                            echo $approved_count;
                            ?>
                        </div>
                        <div class="stat-label">Approved Bookings</div>
                    </div>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="filter-sidebar" style="margin-bottom: 20px;">
                <form method="GET" action="calendar.php">
                    <input type="hidden" name="date" value="<?php echo $current_date; ?>">
                    <input type="hidden" name="view" value="<?php echo $view_type; ?>">
                    <div style="display: flex; gap: 12px; align-items: end;">
                        <div class="form-group" style="flex: 1; margin-bottom: 0;">
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
                        <a href="calendar.php?date=<?php echo date('Y-m-d'); ?>&view=<?php echo $view_type; ?>" class="btn btn-outline">
                            Today
                        </a>
                    </div>
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
                <div class="calendar-day" style="background: var(--bg); cursor: default; border-color: transparent;"></div>
                <?php endfor; ?>
                
                <?php for ($day = 1; $day <= $days_in_month; $day++): 
                    $current_day_date = sprintf('%s-%02d', $selected_month, $day);
                    $is_today = $current_day_date === date('Y-m-d');
                    $day_bookings = $bookings_by_date[$current_day_date] ?? [];
                    $day_reports = $reports_by_date[$current_day_date] ?? [];
                    
                    $has_critical = false;
                    foreach ($day_reports as $report) {
                        if ($report['urgency'] === 'critical') {
                            $has_critical = true;
                            break;
                        }
                    }
                    
                    $has_items = (count($day_bookings) > 0 || count($day_reports) > 0);
                ?>
                <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?> <?php echo $has_critical ? 'has-critical' : ($has_items ? 'has-items' : ''); ?>"
                     onclick="showDayDetails('<?php echo $current_day_date; ?>', '<?php echo $view_type; ?>')">
                    <div class="day-number"><?php echo $day; ?></div>
                    
                    <?php if ($view_type === 'bookings' || $view_type === 'combined'): 
                        $shown = 0;
                        foreach ($day_bookings as $booking):
                            if ($shown >= 2) {
                                echo '<div class="item-indicator" style="background: var(--muted); color: white;">+' . (count($day_bookings) - 2) . ' bookings</div>';
                                break;
                            }
                    ?>
                    <div class="item-indicator booking-<?php echo $booking['status']; ?>">
                        <?php echo date('g:iA', strtotime($booking['start_time'])) . ' ' . 
                                   htmlspecialchars(substr($booking['room_name'], 0, 12)); ?>
                    </div>
                    <?php 
                            $shown++;
                        endforeach;
                    endif;
                    ?>
                    
                    <?php if ($view_type === 'reports' || $view_type === 'combined'): 
                        $shown = 0;
                        foreach ($day_reports as $report):
                            if ($shown >= 2) {
                                echo '<div class="item-indicator" style="background: var(--muted); color: white;">+' . (count($day_reports) - 2) . ' reports</div>';
                                break;
                            }
                    ?>
                    <div class="item-indicator report-<?php echo $report['urgency']; ?>">
                        <i data-lucide="wrench" style="width:10px;height:10px;vertical-align:middle;"></i> <?php echo ucfirst($report['urgency']) . ' - ' . htmlspecialchars(substr($report['room_name'], 0, 10)); ?>
                    </div>
                    <?php 
                            $shown++;
                        endforeach;
                    endif;
                    ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    
    <div id="dayModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Details</h2>
                <button class="close-modal" onclick="closeModal()">×</button>
            </div>
            <div id="modalBody"></div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        const bookingsData = <?php echo json_encode($bookings_by_date); ?>;
        const reportsData = <?php echo json_encode($reports_by_date); ?>;
        
        function showDayDetails(date, viewType) {
            const modal = document.getElementById('dayModal');
            const title = document.getElementById('modalTitle');
            const body = document.getElementById('modalBody');
            
            const dateObj = new Date(date + 'T00:00:00');
            const formattedDate = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
            });
            
            const dayBookings = bookingsData[date] || [];
            const dayReports = reportsData[date] || [];
            
            let html = '';
            
            if (viewType === 'bookings' || viewType === 'combined') {
                html += '<h3 style="color: var(--text); margin-top: 0; font-size: 1em;">Bookings (' + dayBookings.length + ')</h3>';
                if (dayBookings.length > 0) {
                    dayBookings.forEach(booking => {
                        html += generateBookingCard(booking);
                    });
                } else {
                    html += '<p style="color: var(--text-secondary); text-align: center; padding: 20px;">No bookings for this day</p>';
                }
            }
            
            if (viewType === 'reports' || viewType === 'combined') {
                html += '<h3 style="color: var(--text); margin-top: 24px; font-size: 1em;">Maintenance Reports (' + dayReports.length + ')</h3>';
                if (dayReports.length > 0) {
                    dayReports.forEach(report => {
                        html += generateReportCard(report);
                    });
                } else {
                    html += '<p style="color: var(--text-secondary); text-align: center; padding: 20px;">No reports for this day</p>';
                }
            }
            
            title.textContent = formattedDate;
            body.innerHTML = html;
            modal.classList.add('active');
        }
        
        function generateBookingCard(booking) {
            const statusBadge = `<span class="status-badge status-${booking.status}">${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}</span>`;
            return `
                <div class="item-card ${booking.status === 'pending' ? 'pending' : ''}">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin: 0; font-size: 0.95em;">${escapeHtml(booking.room_name)}</h4>
                        ${statusBadge}
                    </div>
                    <div class="item-info">
                        <div><strong>Time:</strong> ${formatTime(booking.start_time)} - ${formatTime(booking.end_time)}</div>
                        <div><strong>Booked By:</strong> ${escapeHtml(booking.booked_by)} (${booking.role})</div>
                        <div><strong>Email:</strong> ${escapeHtml(booking.email)}</div>
                        <div><strong>Purpose:</strong> ${escapeHtml(booking.purpose)}</div>
                    </div>
                    <div style="margin-top: 12px; display: flex; gap: 8px;">
                        <a href="manage_bookings.php?search=${encodeURIComponent(booking.booked_by)}" class="btn" style="padding: 6px 14px; font-size: 0.82em;">View All Bookings</a>
                        ${booking.status === 'pending' ? `
                            <button onclick="quickApprove(${booking.booking_id})" class="btn btn-success" style="padding: 6px 14px; font-size: 0.82em;">✓ Approve</button>
                            <button onclick="quickReject(${booking.booking_id})" class="btn btn-danger" style="padding: 6px 14px; font-size: 0.82em;">✗ Reject</button>
                        ` : ''}
                    </div>
                </div>
            `;
        }
        
        function generateReportCard(report) {
            return `
                <div class="item-card ${report.urgency === 'critical' ? 'critical' : ''}">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin: 0; font-size: 0.95em;">${escapeHtml(report.room_name)}</h4>
                        <span class="status-badge status-${report.urgency}">${report.urgency.toUpperCase()}</span>
                    </div>
                    <div class="item-info">
                        <div><strong>Issue Type:</strong> ${report.issue_type.replace('_', ' ').toUpperCase()}</div>
                        <div><strong>Reported By:</strong> ${escapeHtml(report.reporter_name)} (${report.reporter_role})</div>
                        <div><strong>Status:</strong> ${report.status.replace('_', ' ').toUpperCase()}</div>
                        <div><strong>Reported:</strong> ${new Date(report.reported_at).toLocaleString()}</div>
                    </div>
                    <div style="margin-top: 10px;">
                        <strong>Description:</strong>
                        <p style="background: var(--card-bg); padding: 10px; border-radius: var(--radius-sm); margin: 5px 0; font-size: 0.88em;">${escapeHtml(report.description)}</p>
                    </div>
                    <div style="margin-top: 12px; display: flex; gap: 8px;">
                        <a href="maintenance_reports.php?urgency=${report.urgency}" class="btn" style="padding: 6px 14px; font-size: 0.82em;">View All ${report.urgency} Reports</a>
                        <button onclick="quickUpdateReport(${report.report_id})" class="btn btn-outline" style="padding: 6px 14px; font-size: 0.82em;">Update Status</button>
                    </div>
                </div>
            `;
        }
        
        function quickApprove(bookingId) {
            if (confirm('Approve this booking?')) {
                window.location.href = '../actions/admin_booking_action.php?action=approve&id=' + bookingId;
            }
        }
        
        function quickReject(bookingId) {
            const reason = prompt('Reason for rejection (optional):');
            if (reason !== null) {
                window.location.href = '../actions/admin_booking_action.php?action=reject&id=' + bookingId + '&reason=' + encodeURIComponent(reason);
            }
        }
        
        function quickUpdateReport(reportId) {
            const status = prompt('Update status to:\n- pending\n- in_progress\n- resolved\n- closed');
            if (status && ['pending', 'in_progress', 'resolved', 'closed'].includes(status)) {
                window.location.href = '../actions/admin_maintenance_action.php?action=update&id=' + reportId + '&status=' + status + '&notes=Updated from calendar';
            }
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
            if (e.target === this) closeModal();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
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
