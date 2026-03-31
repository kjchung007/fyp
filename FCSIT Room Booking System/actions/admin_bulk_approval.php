<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$action = $_GET['action'] ?? '';
$admin_id = $_SESSION['user_id'];

if ($action === 'approve_group') {
    // Approve all pending bookings in a recurring group
    $group_id = $_GET['group_id'] ?? '';
    
    if (empty($group_id)) {
        $_SESSION['error'] = "Invalid group ID.";
        header("Location: ../admin/manage_bookings.php");
        exit();
    }
    
    // Get all pending bookings in this group with user info
    $query = "SELECT booking_id, user_id FROM bookings 
              WHERE recurring_group_id = ? AND status = 'pending'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $booking_ids = [];
    $user_ids = [];
    while ($row = $result->fetch_assoc()) {
        $booking_ids[] = $row['booking_id'];
        if (!in_array($row['user_id'], $user_ids)) {
            $user_ids[] = $row['user_id'];
        }
    }
    
    if (count($booking_ids) === 0) {
        $_SESSION['error'] = "No pending bookings found in this group.";
        header("Location: ../admin/manage_bookings.php");
        exit();
    }
    
    // Update all bookings
    $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
    $update = "UPDATE bookings 
               SET status = 'approved', 
                   approved_by = ?, 
                   approved_at = NOW() 
               WHERE booking_id IN ($placeholders)";
    
    $update_stmt = $conn->prepare($update);
    $types = 'i' . str_repeat('i', count($booking_ids));
    $params = array_merge([$admin_id], $booking_ids);
    $update_stmt->bind_param($types, ...$params);
    
    if ($update_stmt->execute()) {
        $count = $update_stmt->affected_rows;
        
        // Notify each user
        foreach ($user_ids as $user_id) {
            create_notification(
                $user_id,
                'booking_approved',
                'Recurring Bookings Approved',
                "Your recurring booking series has been approved by the administrator."
            );
        }
        
        // Log action
        log_action('BULK_APPROVE_GROUP', 'bookings', null, 
                  "Admin approved {$count} bookings in group: {$group_id}");
        
        $_SESSION['success'] = "Successfully approved {$count} booking(s) in this series!";
    } else {
        $_SESSION['error'] = "Failed to approve bookings.";
    }
    
} elseif ($action === 'reject_group') {
    // Reject all pending bookings in a recurring group
    $group_id = $_GET['group_id'] ?? '';
    $reason = $_GET['reason'] ?? 'No reason provided';
    
    if (empty($group_id)) {
        $_SESSION['error'] = "Invalid group ID.";
        header("Location: ../admin/manage_bookings.php");
        exit();
    }
    
    // Get all pending bookings
    $query = "SELECT booking_id, user_id FROM bookings 
              WHERE recurring_group_id = ? AND status = 'pending'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $booking_ids = [];
    $user_ids = [];
    while ($row = $result->fetch_assoc()) {
        $booking_ids[] = $row['booking_id'];
        if (!in_array($row['user_id'], $user_ids)) {
            $user_ids[] = $row['user_id'];
        }
    }
    
    if (count($booking_ids) === 0) {
        $_SESSION['error'] = "No pending bookings found.";
        header("Location: ../admin/manage_bookings.php");
        exit();
    }
    
    // Update all bookings
    $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
    $update = "UPDATE bookings 
               SET status = 'rejected', 
                   admin_remarks = ?,
                   approved_by = ?, 
                   approved_at = NOW() 
               WHERE booking_id IN ($placeholders)";
    
    $update_stmt = $conn->prepare($update);
    $types = 'si' . str_repeat('i', count($booking_ids));
    $params = array_merge([$reason, $admin_id], $booking_ids);
    $update_stmt->bind_param($types, ...$params);
    
    if ($update_stmt->execute()) {
        $count = $update_stmt->affected_rows;
        
        // Notify each user
        foreach ($user_ids as $user_id) {
            create_notification(
                $user_id,
                'booking_rejected',
                'Recurring Bookings Rejected',
                "Your recurring booking series has been rejected. Reason: {$reason}"
            );
        }
        
        // Log action
        log_action('BULK_REJECT_GROUP', 'bookings', null, 
                  "Admin rejected {$count} bookings in group: {$group_id}");
        
        $_SESSION['success'] = "Successfully rejected {$count} booking(s) in this series.";
    } else {
        $_SESSION['error'] = "Failed to reject bookings.";
    }
    
} elseif ($action === 'approve_selected') {
    // Approve selected bookings
    $booking_ids_str = $_GET['booking_ids'] ?? '';
    
    if (empty($booking_ids_str)) {
        $_SESSION['error'] = "No bookings selected.";
        header("Location: ../admin/manage_bookings.php");
        exit();
    }
    
    $booking_ids = explode(',', $booking_ids_str);
    $booking_ids = array_map('intval', $booking_ids);
    
    // Get user IDs
    $placeholders_query = str_repeat('?,', count($booking_ids) - 1) . '?';
    $user_query = "SELECT DISTINCT user_id FROM bookings WHERE booking_id IN ($placeholders_query)";
    $user_stmt = $conn->prepare($user_query);
    $types = str_repeat('i', count($booking_ids));
    $user_stmt->bind_param($types, ...$booking_ids);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    $user_ids = [];
    while ($row = $user_result->fetch_assoc()) {
        $user_ids[] = $row['user_id'];
    }
    
    // Update bookings
    $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
    $update = "UPDATE bookings 
               SET status = 'approved', 
                   approved_by = ?, 
                   approved_at = NOW() 
               WHERE booking_id IN ($placeholders)";
    
    $update_stmt = $conn->prepare($update);
    $types = 'i' . str_repeat('i', count($booking_ids));
    $params = array_merge([$admin_id], $booking_ids);
    $update_stmt->bind_param($types, ...$params);
    
    if ($update_stmt->execute()) {
        $count = $update_stmt->affected_rows;
        
        // Notify users
        foreach ($user_ids as $user_id) {
            create_notification(
                $user_id,
                'booking_approved',
                'Bookings Approved',
                "{$count} of your booking requests have been approved."
            );
        }
        
        // Log action
        log_action('BULK_APPROVE_SELECTED', 'bookings', null, 
                  "Admin approved {$count} selected bookings");
        
        $_SESSION['success'] = "Successfully approved {$count} booking(s)!";
    } else {
        $_SESSION['error'] = "Failed to approve bookings.";
    }
    
} elseif ($action === 'reject_selected') {
    // Reject selected bookings
    $booking_ids_str = $_GET['booking_ids'] ?? '';
    $reason = $_GET['reason'] ?? 'No reason provided';
    
    if (empty($booking_ids_str)) {
        $_SESSION['error'] = "No bookings selected.";
        header("Location: ../admin/manage_bookings.php");
        exit();
    }
    
    $booking_ids = explode(',', $booking_ids_str);
    $booking_ids = array_map('intval', $booking_ids);
    
    // Get user IDs
    $placeholders_query = str_repeat('?,', count($booking_ids) - 1) . '?';
    $user_query = "SELECT DISTINCT user_id FROM bookings WHERE booking_id IN ($placeholders_query)";
    $user_stmt = $conn->prepare($user_query);
    $types = str_repeat('i', count($booking_ids));
    $user_stmt->bind_param($types, ...$booking_ids);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    $user_ids = [];
    while ($row = $user_result->fetch_assoc()) {
        $user_ids[] = $row['user_id'];
    }
    
    // Update bookings
    $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
    $update = "UPDATE bookings 
               SET status = 'rejected', 
                   admin_remarks = ?,
                   approved_by = ?, 
                   approved_at = NOW() 
               WHERE booking_id IN ($placeholders)";
    
    $update_stmt = $conn->prepare($update);
    $types = 'si' . str_repeat('i', count($booking_ids));
    $params = array_merge([$reason, $admin_id], $booking_ids);
    $update_stmt->bind_param($types, ...$params);
    
    if ($update_stmt->execute()) {
        $count = $update_stmt->affected_rows;
        
        // Notify users
        foreach ($user_ids as $user_id) {
            create_notification(
                $user_id,
                'booking_rejected',
                'Bookings Rejected',
                "{$count} of your booking requests have been rejected. Reason: {$reason}"
            );
        }
        
        // Log action
        log_action('BULK_REJECT_SELECTED', 'bookings', null, 
                  "Admin rejected {$count} selected bookings");
        
        $_SESSION['success'] = "Successfully rejected {$count} booking(s).";
    } else {
        $_SESSION['error'] = "Failed to reject bookings.";
    }
    
} else {
    $_SESSION['error'] = "Invalid action.";
}

header("Location: ../admin/manage_bookings.php");
exit();
?>
