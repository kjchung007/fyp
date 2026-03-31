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


// Get unread notifications count
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_count = $notif_stmt->get_result()->fetch_assoc()['unread'];

// Get all facilities for selection
$facilities_result = $conn->query("SELECT * FROM facilities ORDER BY facility_type, facility_name");
$facilities = [];
while ($f = $facilities_result->fetch_assoc()) {
    $facilities[] = $f;
}

// Group facilities by type
$facilities_by_type = [];
foreach ($facilities as $f) {
    $facilities_by_type[$f['facility_type']][] = $f;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Room - Admin</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .image-preview {
            max-width: 300px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 8px;
            display: none;
        }
        .image-upload-area {
            border: 2px dashed #ccc;
            padding: 30px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .image-upload-area:hover {
            border-color: #667eea;
            background: #f8f9fa;
        }
        .image-upload-area.dragover {
            border-color: #667eea;
            background: #e8f4ff;
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
            <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                <a href="manage_rooms.php" class="btn btn-outline">
                    <i data-lucide="arrow-left"></i> Back to Manage Rooms</a>
            </div>
            
            <h2>Add New Room</h2>
            
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
            
            <form action="../actions/add_room_process.php" method="POST" enctype="multipart/form-data">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
                    <div>
                        <div class="form-group">
                            <label for="room_name">Room Name *</label>
                            <input type="text" id="room_name" name="room_name" class="form-control" required>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="room_type">Room Type *</label>
                                <select id="room_type" name="room_type" class="form-control" required>
                                    <option value="">Select Type</option>
                                    <option value="tutorial_room">Tutorial Room</option>
                                    <option value="lecture_hall">Lecture Hall</option>
                                    <option value="lab">Computer Lab</option>
                                    <option value="meeting_room">Meeting Room</option>
                                    <option value="seminar_room">Seminar Room</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="capacity">Capacity *</label>
                                <input type="number" id="capacity" name="capacity" class="form-control" required min="1">
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="building">Building *</label>
                                <input type="text" id="building" name="building" class="form-control" required placeholder="e.g., FCSIT Building">
                            </div>
                            
                            <div class="form-group">
                                <label for="floor">Floor *</label>
                                <select id="floor" name="floor" class="form-control" required>
                                    <option value="">Select Floor</option>
                                    <option value="G">Ground Floor</option>
                                    <option value="1">1st Floor</option>
                                    <option value="2">2nd Floor</option>
                                    <option value="3">3rd Floor</option>
                                    <option value="4">4th Floor</option>
                                    <option value="5">5th Floor</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="4" placeholder="Optional: Describe the room, special features, etc."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status *</label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="available">Available</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="unavailable">Unavailable</option>
                            </select>
                        </div>

                        <!-- Facilities Section -->
                        <div class="facility-section">
                            <div class="section-heading" style="display:flex;align-items:center;gap:8px;margin-top:20px;">
                                <i data-lucide="settings-2" style="width:18px;height:18px;color:var(--primary)"></i>
                                Room Facilities
                            </div>
                            <p class="text-muted text-sm" style="margin-bottom:16px;">Select the facilities available in this room and set their quantity and condition.</p>

                            <?php if (empty($facilities)): ?>
                                <div class="empty-state" style="padding:24px;">
                                    <p>No facilities found. Please add facilities first.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($facilities_by_type as $type => $type_facilities): ?>
                                <div class="facility-type-group">
                                    <div class="facility-type-label"><?php echo ucfirst($type); ?></div>
                                    <div class="facility-list">
                                        <?php foreach ($type_facilities as $facility): ?>
                                        <div class="facility-item" id="facility-item-<?php echo $facility['facility_id']; ?>">
                                            <div class="facility-item-left">
                                                <label class="facility-checkbox-label">
                                                    <input type="checkbox" name="facilities[]" value="<?php echo $facility['facility_id']; ?>" 
                                                           onchange="toggleFacilityOptions(<?php echo $facility['facility_id']; ?>, this.checked)">
                                                    <span class="facility-name"><?php echo htmlspecialchars($facility['facility_name']); ?></span>
                                                </label>
                                                <?php if ($facility['description']): ?>
                                                    <span class="facility-desc"><?php echo htmlspecialchars($facility['description']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="facility-item-options" id="facility-options-<?php echo $facility['facility_id']; ?>" style="display:none;">
                                                <div class="facility-option">
                                                    <label>Qty</label>
                                                    <input type="number" name="facility_qty[<?php echo $facility['facility_id']; ?>]" 
                                                           value="1" min="1" max="999" class="form-control facility-input-sm">
                                                </div>
                                                <div class="facility-option">
                                                    <label>Condition</label>
                                                    <select name="facility_condition[<?php echo $facility['facility_id']; ?>]" class="form-control facility-input-sm">
                                                        <option value="good">Good</option>
                                                        <option value="fair">Fair</option>
                                                        <option value="poor">Poor</option>
                                                        <option value="broken">Broken</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div>
                        <div class="form-group">
                            <label for="room_image">Room Image</label>
                            <input type="file" id="room_image" name="room_image" class="form-control" accept="image/*" onchange="previewImage(this)">
                            
                            <div class="image-upload-area" onclick="document.getElementById('room_image').click();" 
                                 ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" 
                                 ondrop="handleDrop(event)">
                                <p>📷 Click to upload or drag and drop</p>
                                <p style="font-size: 0.9em; color: #7f8c8d;">PNG, JPG, JPEG up to 5MB</p>
                            </div>
                            
                            <img id="imagePreview" class="image-preview" alt="Preview">
                            
                            <p style="font-size: 0.85em; color: #7f8c8d; margin-top: 10px;">
                                Recommended size: 800x600px. Leave empty to use default room icon.
                            </p>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;">
                            <h4 style="margin-bottom: 10px;">Image Guidelines</h4>
                            <ul style="font-size: 0.9em; color: #555; line-height: 1.6; padding-left: 20px;">
                                <li>Clear, well-lit photos of the room</li>
                                <li>Show key facilities and layout</li>
                                <li>Max file size: 5MB</li>
                                <li>Supported formats: JPG, PNG, JPEG</li>
                                <li>Image will be resized to 800x600px</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 30px; text-align: center;">
                    <button type="submit" class="btn" style="gap: 6px;">
                        <i data-lucide="plus" style="width:16px;height:16px"></i> Add Room
                    </button>
                    <a href="manage_rooms.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();

        function toggleFacilityOptions(facilityId, checked) {
            const options = document.getElementById('facility-options-' + facilityId);
            const item = document.getElementById('facility-item-' + facilityId);
            if (checked) {
                options.style.display = 'flex';
                item.classList.add('selected');
            } else {
                options.style.display = 'none';
                item.classList.remove('selected');
            }
        }

        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const uploadArea = document.querySelector('.image-upload-area');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    uploadArea.style.display = 'none';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function handleDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            e.currentTarget.classList.add('dragover');
        }
        
        function handleDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            e.currentTarget.classList.remove('dragover');
        }
        
        function handleDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            e.currentTarget.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            const input = document.getElementById('room_image');
            
            if (files.length > 0) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(files[0]);
                input.files = dataTransfer.files;
                
                const event = new Event('change', { bubbles: true });
                input.dispatchEvent(event);
            }
        }

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
