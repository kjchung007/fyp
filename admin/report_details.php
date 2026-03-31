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

$report_id = intval($_GET['id'] ?? 0);

if ($report_id === 0) {
    header("Location: maintenance_reports.php");
    exit();
}

// Get report details with all related information
$query = "SELECT mr.*, 
          r.room_name, r.building, r.floor, r.room_type, r.capacity,
          f.facility_name, f.facility_type,
          u.name as reporter_name, u.email as reporter_email, u.role as reporter_role,
          a.name as admin_name
          FROM maintenance_reports mr
          JOIN rooms r ON mr.room_id = r.room_id
          LEFT JOIN facilities f ON mr.facility_id = f.facility_id
          JOIN users u ON mr.reported_by = u.user_id
          LEFT JOIN users a ON mr.resolved_by = a.user_id
          WHERE mr.report_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    $_SESSION['error'] = "Report not found.";
    header("Location: maintenance_reports.php");
    exit();
}

// Get status history/comments
$history_query = "SELECT * FROM maintenance_report_comments 
                  WHERE report_id = ? 
                  ORDER BY created_at DESC";
$history_stmt = $conn->prepare($history_query);
$history_stmt->bind_param("i", $report_id);
$history_stmt->execute();
$history = $history_stmt->get_result();

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $_SESSION['user_id']);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

// Function to get urgency badge class
function getUrgencyClass($urgency) {
    switch($urgency) {
        case 'critical': return 'danger';
        case 'high': return 'warning';
        case 'medium': return 'info';
        case 'low': return 'success';
        default: return 'secondary';
    }
}

// Function to get urgency icon
function getUrgencyIcon($urgency) {
    switch($urgency) {
        case 'critical': return 'alert-triangle';
        case 'high': return 'alert-circle';
        case 'medium': return 'clock';
        case 'low': return 'info';
        default: return 'flag';
    }
}

// Function to get status icon
function getStatusIcon($status) {
    switch($status) {
        case 'pending': return 'clock';
        case 'in_progress': return 'loader';
        case 'resolved': return 'check-circle';
        case 'closed': return 'x-circle';
        default: return 'circle';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Details - Maintenance Report #<?php echo $report_id; ?></title>
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
                        <h2>Report Details</h2>
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
                                $notif_list_stmt->bind_param("i", $_SESSION['user_id']);
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
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                <a href="maintenance_reports.php" class="btn btn-outline">
                    <i data-lucide="arrow-left"></i> Back to Reports
                </a>
            </div>

            <!-- Report Header -->
            <div class="admin-header" style="margin-bottom: 24px;">
                <div>
                    <h1 style="margin:0; font-size:1.5em;">Maintenance Report #<?php echo $report_id; ?></h1>
                    <p style="margin:4px 0 0; opacity:0.85; font-size:0.9em;">Submitted on <?php echo date('F j, Y \a\t g:i A', strtotime($report['reported_at'])); ?></p>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="card-container" style="margin-bottom: 24px;">
                <div class="stat-card-modern">
                    <div class="stat-icon <?php echo getUrgencyClass($report['urgency']); ?>">
                        <i data-lucide="<?php echo getUrgencyIcon($report['urgency']); ?>"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value2"><?php echo ucfirst($report['urgency']); ?></div>
                        <div class="stat-label">Urgency</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon blue">
                        <i data-lucide="<?php echo getStatusIcon($report['status']); ?>"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value2"><?php echo ucwords(str_replace('_', ' ', $report['status'])); ?></div>
                        <div class="stat-label">Status</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon purple">
                        <i data-lucide="door-open"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value2"><?php echo htmlspecialchars($report['room_name']); ?></div>
                        <div class="stat-label">Room</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green">
                        <i data-lucide="tag"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value2"><?php echo ucwords(str_replace('_', ' ', $report['issue_type'])); ?></div>
                        <div class="stat-label">Issue Type</div>
                    </div>
                </div>
            </div>

            <!-- Report Information -->
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="file-text"></i>
                    <h3>Report Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="detail-info-grid">
                        <div class="detail-info-item">
                            <span class="detail-info-label">Report ID</span>
                            <span class="detail-info-value">#<?php echo $report_id; ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Status</span>
                            <span class="detail-info-value">
                                <span class="status-badge status-<?php echo $report['status']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $report['status'])); ?>
                                </span>
                            </span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Urgency Level</span>
                            <span class="detail-info-value">
                                <span class="status-badge status-<?php echo getUrgencyClass($report['urgency']); ?>">
                                    <i data-lucide="<?php echo getUrgencyIcon($report['urgency']); ?>" style="width:12px;height:12px"></i>
                                    <?php echo ucfirst($report['urgency']); ?>
                                </span>
                            </span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Issue Type</span>
                            <span class="detail-info-value"><?php echo ucwords(str_replace('_', ' ', $report['issue_type'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Room Information -->
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="door-open"></i>
                    <h3>Room Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="detail-info-grid">
                        <div class="detail-info-item">
                            <span class="detail-info-label">Room Name</span>
                            <span class="detail-info-value">
                                <a href="room_details.php?id=<?php echo $report['room_id']; ?>" style="color:var(--primary); text-decoration:none; font-weight:500;">
                                    <?php echo htmlspecialchars($report['room_name']); ?>
                                </a>
                            </span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Location</span>
                            <span class="detail-info-value"><?php echo htmlspecialchars($report['building']); ?>, Floor <?php echo $report['floor']; ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Room Type</span>
                            <span class="detail-info-value"><?php echo ucwords(str_replace('_', ' ', $report['room_type'])); ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Capacity</span>
                            <span class="detail-info-value"><?php echo $report['capacity']; ?> people</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Facility Information (if applicable) -->
            <?php if ($report['facility_name']): ?>
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="settings"></i>
                    <h3>Facility Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="detail-info-grid">
                        <div class="detail-info-item">
                            <span class="detail-info-label">Facility Name</span>
                            <span class="detail-info-value"><?php echo htmlspecialchars($report['facility_name']); ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Facility Type</span>
                            <span class="detail-info-value"><?php echo ucfirst($report['facility_type']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Issue Description -->
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="message-square"></i>
                    <h3>Issue Description</h3>
                </div>
                <div class="detail-card-body">
                    <div class="info-box info">
                        <p style="margin:0; line-height:1.6;"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Admin Notes -->
            <?php if ($report['admin_notes']): ?>
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="clipboard"></i>
                    <h3>Admin Notes</h3>
                </div>
                <div class="detail-card-body">
                    <div class="info-box warning">
                        <p style="margin:0; line-height:1.6;"><?php echo nl2br(htmlspecialchars($report['admin_notes'])); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reporter Information -->
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="user"></i>
                    <h3>Reported By</h3>
                </div>
                <div class="detail-card-body">
                    <div class="detail-info-grid">
                        <div class="detail-info-item">
                            <span class="detail-info-label">Name</span>
                            <span class="detail-info-value"><?php echo htmlspecialchars($report['reporter_name']); ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Email</span>
                            <span class="detail-info-value"><?php echo htmlspecialchars($report['reporter_email']); ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Role</span>
                            <span class="detail-info-value"><?php echo ucfirst($report['reporter_role']); ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Reported At</span>
                            <span class="detail-info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($report['reported_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resolution Information -->
            <?php if ($report['resolved_at']): ?>
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="check-circle"></i>
                    <h3>Resolution Information</h3>
                </div>
                <div class="detail-card-body">
                    <div class="detail-info-grid">
                        <div class="detail-info-item">
                            <span class="detail-info-label">Resolved By</span>
                            <span class="detail-info-value"><?php echo htmlspecialchars($report['admin_name'] ?? 'System'); ?></span>
                        </div>
                        <div class="detail-info-item">
                            <span class="detail-info-label">Resolved At</span>
                            <span class="detail-info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($report['resolved_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status History/Comments -->
            <?php if ($history->num_rows > 0): ?>
            <div class="detail-card" style="margin-bottom: 20px;">
                <div class="detail-card-header">
                    <i data-lucide="history"></i>
                    <h3>Status History & Comments</h3>
                </div>
                <div class="detail-card-body">
                    <div class="detail-timeline">
                        <?php while ($entry = $history->fetch_assoc()): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <div class="timeline-time"><?php echo date('M j, Y \a\t g:i A', strtotime($entry['created_at'])); ?></div>
                                <span class="status-badge status-<?php echo $entry['status']; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $entry['status'])); ?>
                                </span>
                                <?php if ($entry['notes']): ?>
                                <div class="timeline-note"><?php echo nl2br(htmlspecialchars($entry['notes'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:24px;">
                <button onclick="openStatusModal(<?php echo $report_id; ?>, '<?php echo $report['status']; ?>', 'Report #<?php echo $report_id; ?> - <?php echo htmlspecialchars($report['room_name'], ENT_QUOTES); ?>')" class="btn">
                    <i data-lucide="refresh-cw"></i> Update Status
                </button>
                <a href="maintenance_reports.php" class="btn btn-outline">
                    <i data-lucide="arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3>
                    <i data-lucide="wrench"></i>
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
                        <i data-lucide="alert-triangle"></i>
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
