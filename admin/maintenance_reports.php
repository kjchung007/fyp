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

// Get filters
$status_filter = $_GET['filter'] ?? '';
$urgency_filter = $_GET['urgency'] ?? '';

// Build query
$query = "SELECT mr.*, r.room_name, r.building, f.facility_name, u.name as reporter_name
          FROM maintenance_reports mr
          JOIN rooms r ON mr.room_id = r.room_id
          LEFT JOIN facilities f ON mr.facility_id = f.facility_id
          JOIN users u ON mr.reported_by = u.user_id
          WHERE 1=1";

if ($status_filter) {
    $query .= " AND mr.status = '" . sanitize_input($status_filter) . "'";
}

if ($urgency_filter) {
    $query .= " AND mr.urgency = '" . sanitize_input($urgency_filter) . "'";
}

$query .= " ORDER BY 
            CASE mr.urgency 
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            mr.reported_at DESC";

$reports = $conn->query($query);

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM maintenance_reports")->fetch_assoc()['count'],
    'pending' => $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE status = 'pending'")->fetch_assoc()['count'],
    'in_progress' => $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE status = 'in_progress'")->fetch_assoc()['count'],
    'critical' => $conn->query("SELECT COUNT(*) as count FROM maintenance_reports WHERE urgency = 'critical' AND status != 'resolved'")->fetch_assoc()['count']
];

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
    <title>Maintenance Reports - Admin</title>
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
                <li class="active"><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
                    <?php if ($sidebar_stats['pending_reports'] > 0): ?>
                    <span class="sidebar-badge" style="background:var(--warning)"><?php echo $stats['pending_reports']; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="system_logs.php"><i data-lucide="scroll-text"></i> <span>System Logs</span></a></li>
                <li><a href="calendar.php"><i data-lucide="calendar-days"></i> <span>Calendar View</span></a></li>
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
                        <h2>Maintenance</h2>
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
                <h2>Maintenance Reports</h2>
                <p>Track and manage facility maintenance issues.</p>
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
                    <div class="stat-icon purple"><i data-lucide="file-text" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon orange"><i data-lucide="clock" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon blue"><i data-lucide="loader" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon red"><i data-lucide="alert-triangle" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['critical']; ?></div>
                        <div class="stat-label">Critical Issues</div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-sidebar">
                <form method="GET" action="maintenance_reports.php">
                    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Status</label>
                            <select name="filter" class="form-control">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Urgency</label>
                            <select name="urgency" class="form-control">
                                <option value="">All Levels</option>
                                <option value="critical" <?php echo $urgency_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                                <option value="high" <?php echo $urgency_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $urgency_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $urgency_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn">Filter</button>
                            <a href="maintenance_reports.php" class="btn btn-outline">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Reports as Card List -->
            <div class="section-heading">Maintenance Issues (<?php echo $reports->num_rows; ?> found)</div>
            <div class="card-list">
                <?php if ($reports->num_rows > 0): ?>
                <?php while ($report = $reports->fetch_assoc()): ?>
                <div class="card-row">
                    <div class="card-row-left">
                        <div class="card-row-icon <?php echo $report['urgency'] === 'critical' ? 'red' : ($report['urgency'] === 'high' ? 'orange' : ($report['urgency'] === 'medium' ? 'blue' : '')); ?>">
                            <i data-lucide="wrench" style="width:20px;height:20px"></i>
                        </div>
                        <div class="card-row-info">
                            <h3>
                                <?php echo htmlspecialchars($report['room_name']); ?>
                                <span class="status-badge status-<?php echo $report['urgency']; ?>"><?php echo ucfirst($report['urgency']); ?></span>
                            </h3>
                            <div class="card-row-meta">
                                <span><i data-lucide="tag" style="width:12px;height:12px"></i> <?php echo ucwords(str_replace('_', ' ', $report['issue_type'])); ?></span>
                                <span><i data-lucide="user" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($report['reporter_name']); ?></span>
                                <span><i data-lucide="clock" style="width:12px;height:12px"></i> <?php echo date('M j, Y g:i A', strtotime($report['reported_at'])); ?></span>
                                <span><i data-lucide="map-pin" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($report['building']); ?></span>
                            </div>
                            <?php if ($report['facility_name']): ?>
                            <div class="card-row-purpose">Facility: <?php echo htmlspecialchars($report['facility_name']); ?></div>
                            <?php endif; ?>
                            <div class="card-row-purpose"><?php echo htmlspecialchars(substr($report['description'], 0, 100)) . (strlen($report['description']) > 100 ? '...' : ''); ?></div>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <span class="status-badge status-<?php echo $report['status']; ?>"><?php echo ucwords(str_replace('_', ' ', $report['status'])); ?></span>
                        <button onclick="openStatusModal(<?php echo $report['report_id']; ?>, '<?php echo $report['status']; ?>', 'Report #<?php echo $report['report_id']; ?> - <?php echo htmlspecialchars($report['room_name'], ENT_QUOTES); ?>')" class="btn-icon warning" title="Update Status">
                            <i data-lucide="refresh-cw" style="width:16px;height:16px"></i>
                        </button>
                        <a href="report_details.php?id=<?php echo $report['report_id']; ?>" class="btn-icon" title="View Details"><i data-lucide="eye" style="width:16px;height:16px"></i></a>
                    </div>
                </div>
                <?php endwhile; ?>
                <?php else: ?>
                <div class="empty-state">No maintenance reports found matching the filters.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3>
                    <i data-lucide="wrench" style="width:18px;height:18px;"></i>
                    Update Maintenance Status
                </h3>
                <button class="modal-close" onclick="closeStatusModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="modal-info-box">
                    <div class="modal-title" id="modalReportTitle">Maintenance Report</div>
                    <div class="modal-subtitle">Current Status: <span id="modalCurrentStatus"></span></div>
                </div>
                
                <form id="statusUpdateForm">
                    <input type="hidden" id="modalReportId" name="report_id">
                    
                    <div class="modal-form-group">
                        <label>Update Status</label>
                        <div class="status-options" id="statusOptions">
                            <div class="status-option" data-status="pending" onclick="selectStatus('pending')">
                                <input type="radio" name="status" value="pending" id="statusPending">
                                <div class="status-option-content">
                                    <div class="status-option-title">
                                        <span class="status-badge-pending">Pending</span>
                                    </div>
                                    <div class="status-option-desc">Report received, waiting for review.</div>
                                </div>
                            </div>
                            
                            <div class="status-option" data-status="in_progress" onclick="selectStatus('in_progress')">
                                <input type="radio" name="status" value="in_progress" id="statusInProgress">
                                <div class="status-option-content">
                                    <div class="status-option-title">
                                        <span class="status-badge-in_progress">In Progress</span>
                                    </div>
                                    <div class="status-option-desc">Maintenance work is currently being performed.</div>
                                </div>
                            </div>
                            
                            <div class="status-option" data-status="resolved" onclick="selectStatus('resolved')">
                                <input type="radio" name="status" value="resolved" id="statusResolved">
                                <div class="status-option-content">
                                    <div class="status-option-title">
                                        <span class="status-badge-resolved">Resolved</span>
                                    </div>
                                    <div class="status-option-desc">Issue has been fixed and resolved.</div>
                                </div>
                            </div>
                            
                            <div class="status-option" data-status="closed" onclick="selectStatus('closed')">
                                <input type="radio" name="status" value="closed" id="statusClosed">
                                <div class="status-option-content">
                                    <div class="status-option-title">
                                        <span class="status-badge-closed">Closed</span>
                                    </div>
                                    <div class="status-option-desc">Report is closed (may be duplicate or invalid).</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-form-group">
                        <label>Admin Notes (Optional)</label>
                        <textarea id="adminNotes" class="modal-textarea" rows="3" placeholder="Add notes about the maintenance action taken..."></textarea>
                    </div>
                    
                    <div id="warningNote" class="warning-note" style="display: none;">
                        <i data-lucide="alert-triangle" style="width:16px;height:16px;"></i>
                        <span>Note: Changing status will notify the reporter of this update.</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancel</button>
                <button type="button" class="btn" onclick="submitStatusUpdate()">Update Status</button>
            </div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        let currentReport = {
            id: null,
            currentStatus: null
        };

        // Open Status Modal
        function openStatusModal(reportId, currentStatus, reportInfo) {
            currentReport = {
                id: reportId,
                currentStatus: currentStatus
            };
            
            document.getElementById('modalReportId').value = reportId;
            document.getElementById('modalReportTitle').textContent = reportInfo;
            
            // Set current status display
            const statusBadges = {
                'pending': '<span class="status-badge-pending">Pending</span>',
                'in_progress': '<span class="status-badge-in_progress">In Progress</span>',
                'resolved': '<span class="status-badge-resolved">Resolved</span>',
                'closed': '<span class="status-badge-closed">Closed</span>'
            };
            document.getElementById('modalCurrentStatus').innerHTML = statusBadges[currentStatus] || currentStatus;
            
            // Reset selection
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
                const radio = opt.querySelector('input[type="radio"]');
                radio.checked = false;
            });
            
            // Pre-select current status
            const currentOption = document.querySelector(`.status-option[data-status="${currentStatus}"]`);
            if (currentOption) {
                currentOption.classList.add('selected');
                const radio = currentOption.querySelector('input[type="radio"]');
                radio.checked = true;
            }
            
            // Clear notes
            document.getElementById('adminNotes').value = '';
            
            // Show warning note
            document.getElementById('warningNote').style.display = 'flex';
            
            document.getElementById('statusModal').classList.add('active');
        }

        function selectStatus(status) {
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('selected');
                const radio = opt.querySelector('input[type="radio"]');
                if (opt.getAttribute('data-status') === status) {
                    radio.checked = true;
                    opt.classList.add('selected');
                } else {
                    radio.checked = false;
                }
            });
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }

        function submitStatusUpdate() {
            const selectedStatus = document.querySelector('input[name="status"]:checked');
            if (!selectedStatus) {
                alert('Please select a status');
                return;
            }
            
            const newStatus = selectedStatus.value;
            const reportId = currentReport.id;
            const notes = document.getElementById('adminNotes').value;
            
            if (newStatus === currentReport.currentStatus && !notes) {
                closeStatusModal();
                return;
            }
            
            // Confirm status change
            const statusNames = {
                'pending': 'Pending',
                'in_progress': 'In Progress',
                'resolved': 'Resolved',
                'closed': 'Closed'
            };
            
            if (!confirm(`Are you sure you want to change this report status to ${statusNames[newStatus]}?`)) {
                return;
            }
            
            // Redirect to update action
            window.location.href = '../actions/admin_maintenance_action.php?action=update&id=' + reportId + 
                                '&status=' + newStatus + '&notes=' + encodeURIComponent(notes);
        }
        
        // Close modal when clicking outside
        document.getElementById('statusModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatusModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStatusModal();
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
            fetch('../actions/mark_notification_read.php', {
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
            fetch('../actions/mark_all_notifications_read.php', {method: 'POST'})
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
