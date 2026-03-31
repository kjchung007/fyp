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

// Get all rooms with facility count
$rooms_query = "SELECT r.*, 
                COUNT(DISTINCT rf.facility_id) as facility_count,
                COUNT(DISTINCT CASE WHEN b.status = 'approved' AND b.booking_date >= CURDATE() THEN b.booking_id END) as upcoming_bookings
                FROM rooms r
                LEFT JOIN room_facilities rf ON r.room_id = rf.room_id
                LEFT JOIN bookings b ON r.room_id = b.room_id
                GROUP BY r.room_id
                ORDER BY r.building, r.floor, r.room_name";
$rooms = $conn->query($rooms_query);

// Get statistics
$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM rooms")->fetch_assoc()['count'],
    'available' => $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'available'")->fetch_assoc()['count'],
    'maintenance' => $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'maintenance'")->fetch_assoc()['count'],
    'unavailable' => $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'unavailable'")->fetch_assoc()['count']
];

// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $_SESSION['user_id']);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Rooms - Admin</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        /* Modal Styles */
        .status-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .status-modal.active {
            display: flex;
        }
        
        .modal-container {
            background: var(--card-bg);
            border-radius: var(--radius);
            width: 90%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 1.1em;
            font-weight: 600;
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.2s;
            line-height: 1;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }
        
        .modal-close:hover {
            background: var(--bg);
            color: var(--text);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .room-info {
            background: var(--bg);
            padding: 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 24px;
            text-align: center;
        }
        
        .room-info .room-name {
            font-weight: 600;
            font-size: 1.1em;
            color: var(--text);
            margin-bottom: 4px;
        }
        
        .room-info .current-status {
            font-size: 0.85em;
            color: var(--text-secondary);
        }
        
        .status-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .status-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s;
            background: var(--card-bg);
        }
        
        .status-option:hover {
            border-color: var(--primary-light);
            background: var(--primary-bg);
        }
        
        .status-option.selected {
            border-color: var(--primary);
            background: var(--primary-bg);
        }
        
        .status-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        
        .status-option-content {
            flex: 1;
        }
        
        .status-option-title {
            font-weight: 600;
            font-size: 0.95em;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .status-option-desc {
            font-size: 0.75em;
            color: var(--text-secondary);
        }
        
        .status-badge-available {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 600;
            background: var(--success-bg);
            color: var(--success);
        }
        
        .status-badge-maintenance {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 600;
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .status-badge-unavailable {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 600;
            background: var(--danger-bg);
            color: var(--danger);
        }
        
        .modal-footer {
            padding: 16px 24px 24px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid var(--border);
        }
        
        .modal-footer .btn {
            padding: 8px 20px;
        }
        
        .warning-note {
            background: var(--warning-bg);
            padding: 12px;
            border-radius: var(--radius-sm);
            font-size: 0.8em;
            color: #92400e;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
        }
    </style>
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
                <li class="active"><a href="manage_rooms.php"><i data-lucide="door-open"></i> <span>Manage Rooms</span></a></li>
                <li><a href="manage_users.php"><i data-lucide="users"></i> <span>Manage Users</span></a></li>
                <li><a href="maintenance_reports.php"><i data-lucide="wrench"></i> <span>Maintenance</span>
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
                        <h2>Manage Rooms</h2>
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div class="admin-header" style="margin-bottom: 0;">
                    <h2>Manage Rooms</h2>
                    <p>Add, edit, and manage all rooms and facilities.</p>
                </div>
                <a href="add_room.php" class="btn" style="gap: 6px;">
                    <i data-lucide="plus" style="width:16px;height:16px"></i> Add Room
                </a>
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
                    <div class="stat-icon purple"><i data-lucide="door-open" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Rooms</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon green"><i data-lucide="check-circle-2" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['available']; ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon orange"><i data-lucide="wrench" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['maintenance']; ?></div>
                        <div class="stat-label">Maintenance</div>
                    </div>
                </div>
                <div class="stat-card-modern">
                    <div class="stat-icon red"><i data-lucide="x-circle" style="width:20px;height:20px"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['unavailable']; ?></div>
                        <div class="stat-label">Unavailable</div>
                    </div>
                </div>
            </div>
            
            <!-- Rooms as Card List -->
            <div class="section-heading">Room Inventory</div>
            <div class="card-list">
                <?php while ($room = $rooms->fetch_assoc()): ?>
                <div class="card-row" style="<?php echo $room['status'] === 'unavailable' ? 'opacity: 0.6;' : ''; ?>">
                    <div class="card-row-left">
                        <div class="room-thumb">
                            <?php if ($room['image_url']): ?>
                                <img src="../<?php echo $room['image_url']; ?>" alt="<?php echo htmlspecialchars($room['room_name']); ?>">
                            <?php else: ?>
                                <i data-lucide="door-open" style="width:20px;height:20px;color:var(--primary)"></i>
                            <?php endif; ?>
                        </div>
                        <div class="card-row-info">
                            <h3><?php echo htmlspecialchars($room['room_name']); ?></h3>
                            <div class="card-row-meta">
                                <span><i data-lucide="users" style="width:12px;height:12px"></i> <?php echo $room['capacity']; ?></span>
                                <span><i data-lucide="map-pin" style="width:12px;height:12px"></i> <?php echo htmlspecialchars($room['building']); ?>, Floor <?php echo $room['floor']; ?></span>
                                <span><?php echo $room['facility_count']; ?> facilities</span>
                                <span><?php echo $room['upcoming_bookings']; ?> upcoming</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-row-right">
                        <span class="status-badge status-<?php echo $room['status'] === 'available' ? 'available' : ($room['status'] === 'maintenance' ? 'maintenance' : 'unavailable'); ?>">
                            <?php echo ucfirst($room['status']); ?>
                        </span>
                        <a href="room_details.php?id=<?php echo $room['room_id']; ?>" class="btn-icon" title="View"><i data-lucide="eye" style="width:15px;height:15px"></i></a>
                        <a href="edit_room.php?id=<?php echo $room['room_id']; ?>" class="btn-icon" title="Edit"><i data-lucide="pencil" style="width:15px;height:15px"></i></a>
                        <button onclick="openStatusModal(<?php echo $room['room_id']; ?>, '<?php echo htmlspecialchars($room['room_name'], ENT_QUOTES); ?>', '<?php echo $room['status']; ?>')" class="btn-icon warning" title="Change Status"><i data-lucide="refresh-cw" style="width:15px;height:15px"></i></button>
                        <button onclick="deleteRoom(<?php echo $room['room_id']; ?>, '<?php echo htmlspecialchars($room['room_name'], ENT_QUOTES); ?>')" class="btn-icon danger" title="Delete"><i data-lucide="trash-2" style="width:15px;height:15px"></i></button>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div id="statusModal" class="status-modal">
        <div class="modal-container">
            <div class="modal-header">
                <h3>
                    <i data-lucide="refresh-cw" style="width:18px;height:18px;"></i>
                    Change Room Status
                </h3>
                <button class="modal-close" onclick="closeStatusModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="room-info">
                    <div class="room-name" id="modalRoomName">Room Name</div>
                    <div class="current-status">Current Status: <span id="modalCurrentStatus"></span></div>
                </div>
                
                <form id="statusChangeForm">
                    <input type="hidden" id="modalRoomId" name="room_id">
                    <div class="status-options">
                        <div class="status-option" data-status="available" onclick="selectStatus('available')">
                            <input type="radio" name="status" value="available" id="statusAvailable">
                            <div class="status-option-content">
                                <div class="status-option-title">
                                    <span class="status-badge-available">Available</span>
                                </div>
                                <div class="status-option-desc">Room is ready for booking. Users can book this room.</div>
                            </div>
                        </div>
                        
                        <div class="status-option" data-status="maintenance" onclick="selectStatus('maintenance')">
                            <input type="radio" name="status" value="maintenance" id="statusMaintenance">
                            <div class="status-option-content">
                                <div class="status-option-title">
                                    <span class="status-badge-maintenance">Maintenance</span>
                                </div>
                                <div class="status-option-desc">Room is under maintenance. Temporarily unavailable for booking.</div>
                            </div>
                        </div>
                        
                        <div class="status-option" data-status="unavailable" onclick="selectStatus('unavailable')">
                            <input type="radio" name="status" value="unavailable" id="statusUnavailable">
                            <div class="status-option-content">
                                <div class="status-option-title">
                                    <span class="status-badge-unavailable">Unavailable</span>
                                </div>
                                <div class="status-option-desc">Room is permanently unavailable. Users cannot book this room.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="warning-note" id="warningNote" style="display: none;">
                        <i data-lucide="alert-triangle" style="width:16px;height:16px;"></i>
                        <span>Warning: Changing status to Maintenance or Unavailable will affect existing bookings.</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancel</button>
                <button type="button" class="btn" onclick="submitStatusChange()">Apply Changes</button>
            </div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
        
        let currentSelectedRoom = {
            id: null,
            name: null,
            currentStatus: null
        };
        
        // Open Status Modal
        function openStatusModal(roomId, roomName, currentStatus) {
            currentSelectedRoom = {
                id: roomId,
                name: roomName,
                currentStatus: currentStatus
            };
            
            document.getElementById('modalRoomId').value = roomId;
            document.getElementById('modalRoomName').textContent = roomName;
            
            const currentStatusSpan = document.getElementById('modalCurrentStatus');
            currentStatusSpan.innerHTML = getStatusBadge(currentStatus);
            currentStatusSpan.className = '';
            
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
            
            // Show warning if changing to maintenance or unavailable
            const warningNote = document.getElementById('warningNote');
            if (currentStatus !== 'available') {
                warningNote.style.display = 'flex';
            } else {
                warningNote.style.display = 'none';
            }
            
            document.getElementById('statusModal').classList.add('active');
        }
        
        function getStatusBadge(status) {
            const badges = {
                'available': '<span class="status-badge-available">Available</span>',
                'maintenance': '<span class="status-badge-maintenance">Maintenance</span>',
                'unavailable': '<span class="status-badge-unavailable">Unavailable</span>'
            };
            return badges[status] || status;
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
            
            // Update warning based on selection
            const warningNote = document.getElementById('warningNote');
            if (status !== 'available') {
                warningNote.style.display = 'flex';
            } else {
                warningNote.style.display = 'none';
            }
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }
        
        function submitStatusChange() {
            const selectedStatus = document.querySelector('input[name="status"]:checked');
            if (!selectedStatus) {
                alert('Please select a status');
                return;
            }
            
            const newStatus = selectedStatus.value;
            const roomId = currentSelectedRoom.id;
            
            if (newStatus === currentSelectedRoom.currentStatus) {
                closeStatusModal();
                return;
            }
            
            // Confirm if changing to maintenance or unavailable
            if (newStatus !== 'available') {
                if (!confirm(`Are you sure you want to change "${currentSelectedRoom.name}" to ${newStatus.toUpperCase()}?\n\nThis may affect existing bookings for this room.`)) {
                    return;
                }
            }
            
            // Redirect to status change action
            window.location.href = '../actions/admin_room_action.php?action=status&id=' + roomId + '&status=' + newStatus;
        }
        
        function deleteRoom(roomId, roomName) {
            if (confirm('Are you sure you want to delete "' + roomName + '"?\n\nThis will also delete:\n- All associated facilities\n- All booking records\n- All maintenance reports\n\nThis action cannot be undone!')) {
                window.location.href = '../actions/admin_room_action.php?action=delete&id=' + roomId;
            }
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