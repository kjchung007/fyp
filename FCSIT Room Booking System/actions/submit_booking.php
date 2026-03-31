<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../browse_rooms.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$room_id = intval($_POST['room_id']);
$booking_date = sanitize_input($_POST['booking_date']);
$start_time = sanitize_input($_POST['start_time']);
$end_time = sanitize_input($_POST['end_time']);
$purpose = sanitize_input($_POST['purpose']);
$enable_recurring = isset($_POST['enable_recurring']) && $_POST['enable_recurring'] === 'on';

// Validation
if (!$room_id || !$booking_date || !$start_time || !$end_time || !$purpose) {
    $_SESSION['error'] = "All fields are required.";
    header("Location: ../book_room.php?id=$room_id");
    exit();
}

// Check if booking date is in the future
if (strtotime($booking_date) < strtotime(date('Y-m-d'))) {
    $_SESSION['error'] = "Cannot book rooms for past dates.";
    header("Location: ../book_room.php?id=$room_id");
    exit();
}

// Check if end time is after start time
if ($end_time <= $start_time) {
    $_SESSION['error'] = "End time must be after start time.";
    header("Location: ../book_room.php?id=$room_id");
    exit();
}

// Function to check if slot is available
function checkSlotAvailability($conn, $room_id, $date, $start_time, $end_time) {
    $conflict_query = "SELECT COUNT(*) as conflicts 
                       FROM bookings 
                       WHERE room_id = ? 
                       AND booking_date = ? 
                       AND status IN ('pending', 'approved')
                       AND (
                           (start_time < ? AND end_time > ?) OR
                           (start_time < ? AND end_time > ?) OR
                           (start_time >= ? AND end_time <= ?)
                       )";
    
    $stmt = $conn->prepare($conflict_query);
    $stmt->bind_param("isssssss", $room_id, $date, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['conflicts'] == 0;
}

// Function to insert a booking
function insertBooking($conn, $user_id, $room_id, $date, $start_time, $end_time, $purpose, $recurring_group_id = null) {
    $query = "INSERT INTO bookings (user_id, room_id, booking_date, start_time, end_time, purpose, status, recurring_group_id) 
              VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iisssss", $user_id, $room_id, $date, $start_time, $end_time, $purpose, $recurring_group_id);
    return $stmt->execute() ? $conn->insert_id : false;
}

// Generate recurring dates
function generateRecurringDates($start_date, $pattern, $occurrences) {
    $dates = [$start_date];
    $start = new DateTime($start_date);
    
    for ($i = 1; $i < $occurrences; $i++) {
        $next_date = clone $start;
        
        switch($pattern) {
            case 'weekly':
                $next_date->modify('+' . $i . ' weeks');
                break;
            case 'biweekly':
                $next_date->modify('+' . ($i * 2) . ' weeks');
                break;
            case 'monthly':
                $next_date->modify('+' . $i . ' months');
                break;
        }
        
        $dates[] = $next_date->format('Y-m-d');
    }
    
    return $dates;
}

// Handle recurring bookings
if ($enable_recurring && check_role(['lecturer', 'admin', 'super_admin'])) {
    $pattern = sanitize_input($_POST['recurrence_pattern'] ?? 'weekly');
    $occurrences = min(intval($_POST['occurrences'] ?? 1), 20); // Max 20
    
    // Generate all dates
    $dates = generateRecurringDates($booking_date, $pattern, $occurrences);
    
    // Generate unique recurring group ID
    $recurring_group_id = 'REC-' . time() . '-' . $user_id;
    
    $successful_bookings = [];
    $failed_bookings = [];
    $skipped_dates = [];
    
    // Attempt to book each date
    foreach ($dates as $date) {
        if (checkSlotAvailability($conn, $room_id, $date, $start_time, $end_time)) {
            $booking_id = insertBooking($conn, $user_id, $room_id, $date, $start_time, $end_time, $purpose, $recurring_group_id);
            if ($booking_id) {
                $successful_bookings[] = [
                    'id' => $booking_id,
                    'date' => $date
                ];
            } else {
                $failed_bookings[] = $date;
            }
        } else {
            $skipped_dates[] = $date;
        }
    }
    
    // Create notification
    if (count($successful_bookings) > 0) {
        create_notification(
            $user_id, 
            'booking_reminder', 
            'Recurring Booking Submitted', 
            count($successful_bookings) . ' booking requests have been submitted for approval.'
        );
        
        // Log action
        log_action('RECURRING_BOOKING_CREATED', 'bookings', null, 
                  "Recurring booking group {$recurring_group_id}: " . count($successful_bookings) . " bookings created");
    }
    
    // Build success message
    $message = "Recurring booking request submitted!<br>";
    $message .= "✓ " . count($successful_bookings) . " bookings created successfully<br>";
    
    if (count($skipped_dates) > 0) {
        $message .= "⚠ " . count($skipped_dates) . " dates skipped due to conflicts<br>";
    }
    
    if (count($failed_bookings) > 0) {
        $message .= "✗ " . count($failed_bookings) . " bookings failed";
    }
    
    $_SESSION['success'] = $message;
    $_SESSION['recurring_group_id'] = $recurring_group_id; // For easy cancellation
    header("Location: ../my_bookings.php");
    exit();
    
} else {
    // Single booking (original logic)
    if (!checkSlotAvailability($conn, $room_id, $booking_date, $start_time, $end_time)) {
        $_SESSION['error'] = "Time slot conflict detected! Please choose a different time.";
        header("Location: ../book_room.php?id=$room_id");
        exit();
    }
    
    $booking_id = insertBooking($conn, $user_id, $room_id, $booking_date, $start_time, $end_time, $purpose);
    
    if ($booking_id) {
        create_notification(
            $user_id, 
            'booking_reminder', 
            'Booking Request Submitted', 
            'Your booking request has been submitted and is pending approval.'
        );
        
        log_action('BOOKING_CREATED', 'bookings', $booking_id, "New booking request submitted for room $room_id");
        
        $_SESSION['success'] = "Booking request submitted successfully! You will receive a notification once it's reviewed.";
        header("Location: ../my_bookings.php");
        exit();
    } else {
        $_SESSION['error'] = "Failed to submit booking request. Please try again.";
        header("Location: ../book_room.php?id=$room_id");
        exit();
    }
}
