<?php
session_start();
require_once '../config.php';

if (!is_logged_in()) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$mode = $_GET['mode'] ?? '';

if ($mode === 'all') {
    // Cancel all future bookings in a recurring group
    $group_id = $_GET['group_id'] ?? '';
    
    if (empty($group_id)) {
        $_SESSION['error'] = "Invalid recurring group.";
        header("Location: ../my_bookings.php");
        exit();
    }
    
    // Get all future bookings in this group
    $query = "UPDATE bookings 
              SET status = 'cancelled', updated_at = NOW()
              WHERE recurring_group_id = ?
              AND user_id = ?
              AND booking_date >= CURDATE()
              AND status IN ('pending', 'approved')";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $group_id, $user_id);
    
    if ($stmt->execute()) {
        $cancelled_count = $stmt->affected_rows;
        
        // Create notification
        create_notification(
            $user_id,
            'system',
            'Recurring Bookings Cancelled',
            "You have cancelled {$cancelled_count} future booking(s) in this recurring series."
        );
        
        // Log action
        log_action('RECURRING_CANCELLED_ALL', 'bookings', null, 
                  "User cancelled all future bookings in group: {$group_id}");
        
        $_SESSION['success'] = "Successfully cancelled {$cancelled_count} future booking(s).";
    } else {
        $_SESSION['error'] = "Failed to cancel bookings. Please try again.";
    }
    
} elseif ($mode === 'selected') {
    // Cancel selected bookings
    $booking_ids_str = $_GET['booking_ids'] ?? '';
    
    if (empty($booking_ids_str)) {
        $_SESSION['error'] = "No bookings selected.";
        header("Location: ../my_bookings.php");
        exit();
    }
    
    $booking_ids = explode(',', $booking_ids_str);
    $booking_ids = array_map('intval', $booking_ids);
    
    if (empty($booking_ids)) {
        $_SESSION['error'] = "Invalid booking selection.";
        header("Location: ../my_bookings.php");
        exit();
    }
    
    $placeholders = str_repeat('?,', count($booking_ids) - 1) . '?';
    
    $query = "UPDATE bookings 
              SET status = 'cancelled', updated_at = NOW()
              WHERE booking_id IN ($placeholders)
              AND user_id = ?
              AND status IN ('pending', 'approved')";
    
    $stmt = $conn->prepare($query);
    
    // Bind parameters dynamically
    $types = str_repeat('i', count($booking_ids)) . 'i';
    $params = array_merge($booking_ids, [$user_id]);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $cancelled_count = $stmt->affected_rows;
        
        // Create notification
        create_notification(
            $user_id,
            'system',
            'Bookings Cancelled',
            "You have cancelled {$cancelled_count} booking(s)."
        );
        
        // Log action
        log_action('BOOKINGS_CANCELLED_BULK', 'bookings', null, 
                  "User cancelled {$cancelled_count} selected bookings");
        
        $_SESSION['success'] = "Successfully cancelled {$cancelled_count} booking(s).";
    } else {
        $_SESSION['error'] = "Failed to cancel bookings. Please try again.";
    }
    
} else {
    $_SESSION['error'] = "Invalid cancellation mode.";
}

header("Location: ../my_bookings.php");
exit();
?>
