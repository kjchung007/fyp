<?php
session_start();
require_once '../config.php';

if (!is_logged_in()) {
    header("Location: ../login.php");
    exit();
}

$booking_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];

if (!$booking_id) {
    $_SESSION['error'] = "Invalid booking ID.";
    header("Location: ../my_bookings.php");
    exit();
}

// Verify booking belongs to user
$query = "SELECT * FROM bookings WHERE booking_id = ? AND user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['error'] = "Booking not found or you don't have permission to cancel it.";
    header("Location: ../my_bookings.php");
    exit();
}

// Check if booking can be cancelled
if ($booking['status'] === 'cancelled' || $booking['status'] === 'rejected') {
    $_SESSION['error'] = "This booking is already cancelled or rejected.";
    header("Location: ../my_bookings.php");
    exit();
}

// Update booking status
$update_query = "UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE booking_id = ?";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bind_param("i", $booking_id);

if ($update_stmt->execute()) {
    // Create notification
    create_notification(
        $user_id,
        'system',
        'Booking Cancelled',
        'Your booking has been cancelled successfully.'
    );
    
    // Log action
    log_action('BOOKING_CANCELLED', 'bookings', $booking_id, "User cancelled booking");
    
    $_SESSION['success'] = "Booking cancelled successfully.";
} else {
    $_SESSION['error'] = "Failed to cancel booking. Please try again.";
}

header("Location: ../my_bookings.php");
exit();
?>