<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$action = $_GET['action'] ?? '';
$booking_id = intval($_GET['id'] ?? 0);

if (!$booking_id || !$action) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ../admin/manage_bookings.php");
    exit();
}

// Get booking details
$query = "SELECT b.*, u.name, u.email FROM bookings b 
          JOIN users u ON b.user_id = u.user_id 
          WHERE b.booking_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['error'] = "Booking not found.";
    header("Location: ../admin/manage_bookings.php");
    exit();
}

switch ($action) {
    case 'approve':
        $update = "UPDATE bookings 
                   SET status = 'approved', 
                       approved_by = ?, 
                       approved_at = NOW() 
                   WHERE booking_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("ii", $_SESSION['user_id'], $booking_id);
        
        if ($update_stmt->execute()) {
            // Create notification
            create_notification(
                $booking['user_id'],
                'booking_approved',
                'Booking Approved',
                'Your booking request has been approved by the administrator.'
            );
            
            // Log action
            log_action('BOOKING_APPROVED', 'bookings', $booking_id, "Admin approved booking");
            
            $_SESSION['success'] = "Booking approved successfully!";
        } else {
            $_SESSION['error'] = "Failed to approve booking.";
        }
        break;
        
    case 'reject':
        $reason = $_GET['reason'] ?? 'No reason provided';
        
        $update = "UPDATE bookings 
                   SET status = 'rejected', 
                       admin_remarks = ?,
                       approved_by = ?, 
                       approved_at = NOW() 
                   WHERE booking_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("sii", $reason, $_SESSION['user_id'], $booking_id);
        
        if ($update_stmt->execute()) {
            // Create notification
            create_notification(
                $booking['user_id'],
                'booking_rejected',
                'Booking Rejected',
                "Your booking request has been rejected. Reason: {$reason}"
            );
            
            // Log action
            log_action('BOOKING_REJECTED', 'bookings', $booking_id, "Admin rejected booking: {$reason}");
            
            $_SESSION['success'] = "Booking rejected successfully.";
        } else {
            $_SESSION['error'] = "Failed to reject booking.";
        }
        break;
        
    case 'delete':
        $delete = "DELETE FROM bookings WHERE booking_id = ?";
        $delete_stmt = $conn->prepare($delete);
        $delete_stmt->bind_param("i", $booking_id);
        
        if ($delete_stmt->execute()) {
            // Log action
            log_action('BOOKING_DELETED', 'bookings', $booking_id, "Admin deleted booking");
            
            $_SESSION['success'] = "Booking deleted successfully.";
        } else {
            $_SESSION['error'] = "Failed to delete booking.";
        }
        break;
        
    default:
        $_SESSION['error'] = "Invalid action.";
}

header("Location: ../admin/manage_bookings.php");
exit();
?>