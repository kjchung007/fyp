<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$action = $_GET['action'] ?? '';
$room_id = intval($_GET['id'] ?? 0);

if (!$room_id || !$action) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ../admin/manage_rooms.php");
    exit();
}

// Get room details
$query = "SELECT * FROM rooms WHERE room_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $room_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

if (!$room) {
    $_SESSION['error'] = "Room not found.";
    header("Location: ../admin/manage_rooms.php");
    exit();
}

switch ($action) {
    case 'status':
        $new_status = $_GET['status'] ?? '';
        
        // Validate status
        $valid_statuses = ['available', 'maintenance', 'unavailable'];
        if (!in_array($new_status, $valid_statuses)) {
            $_SESSION['error'] = "Invalid status value.";
            header("Location: ../admin/manage_rooms.php");
            exit();
        }
        
        // Update room status
        $update = "UPDATE rooms SET status = ?, updated_at = NOW() WHERE room_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $new_status, $room_id);
        
        if ($update_stmt->execute()) {
            // If changing to maintenance, create notification for users with upcoming bookings
            if ($new_status === 'maintenance' || $new_status === 'unavailable') {
                // FIXED: Specify which user_id to use by adding table aliases
                $upcoming_query = "SELECT DISTINCT b.user_id, u.name 
                                   FROM bookings b
                                   JOIN users u ON b.user_id = u.user_id
                                   WHERE b.room_id = ? 
                                   AND b.booking_date >= CURDATE() 
                                   AND b.status = 'approved'";
                $upcoming_stmt = $conn->prepare($upcoming_query);
                $upcoming_stmt->bind_param("i", $room_id);
                $upcoming_stmt->execute();
                $affected_users = $upcoming_stmt->get_result();
                
                $status_message = $new_status == 'maintenance' ? 'under maintenance' : 'unavailable';
                
                while ($user = $affected_users->fetch_assoc()) {
                    create_notification(
                        $user['user_id'], // Now using b.user_id from the query
                        'system',
                        'Room Status Alert',
                        "Room '{$room['room_name']}' has been marked as {$status_message}. Your upcoming booking(s) may be affected. Please contact the administrator if you have any questions."
                    );
                }
            }
            
            // Log action
            log_action('ROOM_STATUS_CHANGED', 'rooms', $room_id, "Status changed to: {$new_status}");
            
            $_SESSION['success'] = "Room status updated to '" . ucfirst($new_status) . "' successfully!";
        } else {
            $_SESSION['error'] = "Failed to update room status.";
        }
        break;
        
    case 'delete':
        // Check if room has upcoming bookings
        // FIXED: Specify table name to avoid ambiguity
        $booking_check = "SELECT COUNT(*) as count FROM bookings 
                          WHERE room_id = ? 
                          AND booking_date >= CURDATE() 
                          AND status = 'approved'";
        $check_stmt = $conn->prepare($booking_check);
        $check_stmt->bind_param("i", $room_id);
        $check_stmt->execute();
        $booking_count = $check_stmt->get_result()->fetch_assoc()['count'];
        
        if ($booking_count > 0) {
            $_SESSION['error'] = "Cannot delete room: {$booking_count} upcoming approved bookings exist. Cancel them first.";
            header("Location: ../admin/manage_rooms.php");
            exit();
        }
        
        // Start transaction for safe deletion
        $conn->begin_transaction();
        
        try {
            // Delete room facilities (will cascade via foreign key)
            $delete_facilities = "DELETE FROM room_facilities WHERE room_id = ?";
            $fac_stmt = $conn->prepare($delete_facilities);
            $fac_stmt->bind_param("i", $room_id);
            $fac_stmt->execute();
            
            // Update maintenance reports (set room_id to NULL or delete)
            $update_reports = "UPDATE maintenance_reports SET room_id = NULL WHERE room_id = ?";
            $rep_stmt = $conn->prepare($update_reports);
            $rep_stmt->bind_param("i", $room_id);
            $rep_stmt->execute();
            
            // Delete past bookings
            $delete_bookings = "DELETE FROM bookings WHERE room_id = ?";
            $book_stmt = $conn->prepare($delete_bookings);
            $book_stmt->bind_param("i", $room_id);
            $book_stmt->execute();
            
            // Finally delete the room
            $delete_room = "DELETE FROM rooms WHERE room_id = ?";
            $room_stmt = $conn->prepare($delete_room);
            $room_stmt->bind_param("i", $room_id);
            $room_stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Log action
            log_action('ROOM_DELETED', 'rooms', $room_id, "Room '{$room['room_name']}' deleted with all associations");
            
            $_SESSION['success'] = "Room '{$room['room_name']}' and all associated data deleted successfully!";
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $_SESSION['error'] = "Failed to delete room: " . $e->getMessage();
        }
        break;
        
    case 'toggle_availability':
        // Quick toggle between available and maintenance
        $new_status = ($room['status'] === 'available') ? 'maintenance' : 'available';
        
        $update = "UPDATE rooms SET status = ?, updated_at = NOW() WHERE room_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $new_status, $room_id);
        
        if ($update_stmt->execute()) {
            log_action('ROOM_TOGGLED', 'rooms', $room_id, "Status toggled to: {$new_status}");
            $_SESSION['success'] = "Room availability toggled successfully!";
        } else {
            $_SESSION['error'] = "Failed to toggle room status.";
        }
        break;
        
    case 'duplicate':
        // Duplicate room with facilities (useful for similar rooms)
        $insert = "INSERT INTO rooms (room_name, room_type, capacity, building, floor, description, status)
                   SELECT CONCAT(room_name, ' (Copy)'), room_type, capacity, building, floor, description, 'available'
                   FROM rooms WHERE room_id = ?";
        $insert_stmt = $conn->prepare($insert);
        $insert_stmt->bind_param("i", $room_id);
        
        if ($insert_stmt->execute()) {
            $new_room_id = $conn->insert_id;
            
            // Copy facilities
            $copy_facilities = "INSERT INTO room_facilities (room_id, facility_id, quantity, condition_status)
                                SELECT ?, facility_id, quantity, 'good'
                                FROM room_facilities WHERE room_id = ?";
            $copy_stmt = $conn->prepare($copy_facilities);
            $copy_stmt->bind_param("ii", $new_room_id, $room_id);
            $copy_stmt->execute();
            
            log_action('ROOM_DUPLICATED', 'rooms', $new_room_id, "Duplicated from room {$room_id}");
            $_SESSION['success'] = "Room duplicated successfully! Please edit the new room details.";
        } else {
            $_SESSION['error'] = "Failed to duplicate room.";
        }
        break;
        
    default:
        $_SESSION['error'] = "Invalid action.";
}

header("Location: ../admin/manage_rooms.php");
exit();
?>