<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$action = $_GET['action'] ?? '';
$report_id = intval($_GET['id'] ?? 0);

if (!$report_id || !$action) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ../admin/maintenance_reports.php");
    exit();
}

// Get report details
$query = "SELECT mr.*, r.room_name, u.name as reporter_name, u.email as reporter_email
          FROM maintenance_reports mr
          JOIN rooms r ON mr.room_id = r.room_id
          JOIN users u ON mr.reported_by = u.user_id
          WHERE mr.report_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $report_id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    $_SESSION['error'] = "Maintenance report not found.";
    header("Location: ../admin/maintenance_reports.php");
    exit();
}

// Helper function to add comment to history
function addHistoryComment($conn, $report_id, $status, $notes, $created_by) {
    $comment_query = "INSERT INTO maintenance_report_comments (report_id, status, notes, created_by, created_at) 
                      VALUES (?, ?, ?, ?, NOW())";
    $comment_stmt = $conn->prepare($comment_query);
    $comment_stmt->bind_param("issi", $report_id, $status, $notes, $created_by);
    return $comment_stmt->execute();
}

switch ($action) {
    case 'update':
        $new_status = $_GET['status'] ?? '';
        $admin_notes = $_GET['notes'] ?? '';
        
        // Validate status
        $valid_statuses = ['pending', 'in_progress', 'resolved', 'closed'];
        if (!in_array($new_status, $valid_statuses)) {
            $_SESSION['error'] = "Invalid status value.";
            header("Location: ../admin/maintenance_reports.php");
            exit();
        }
        
        // Prepare update query
        if ($new_status === 'resolved') {
            // Mark as resolved with timestamp
            $update = "UPDATE maintenance_reports 
                       SET status = ?,
                           admin_notes = ?,
                           resolved_at = NOW(),
                           resolved_by = ?
                       WHERE report_id = ?";
            $update_stmt = $conn->prepare($update);
            $update_stmt->bind_param("ssii", $new_status, $admin_notes, $_SESSION['user_id'], $report_id);
        } else {
            // Regular status update
            $update = "UPDATE maintenance_reports 
                       SET status = ?,
                           admin_notes = ?
                       WHERE report_id = ?";
            $update_stmt = $conn->prepare($update);
            $update_stmt->bind_param("ssi", $new_status, $admin_notes, $report_id);
        }
        
        if ($update_stmt->execute()) {
            // Add to history/comments
            $comment_text = $admin_notes ? "Status changed to " . ucwords(str_replace('_', ' ', $new_status)) . ". Notes: " . $admin_notes : "Status changed to " . ucwords(str_replace('_', ' ', $new_status));
            addHistoryComment($conn, $report_id, $new_status, $comment_text, $_SESSION['user_id']);
            
            // If resolved and was critical, restore room to available
            if ($new_status === 'resolved' && $report['urgency'] === 'critical') {
                $restore_room = "UPDATE rooms SET status = 'available' WHERE room_id = ?";
                $restore_stmt = $conn->prepare($restore_room);
                $restore_stmt->bind_param("i", $report['room_id']);
                $restore_stmt->execute();
            }
            
            // Create notification for reporter
            $status_messages = [
                'pending' => 'Your maintenance report is pending review.',
                'in_progress' => 'Your maintenance report is being worked on.',
                'resolved' => 'Your maintenance report has been resolved!',
                'closed' => 'Your maintenance report has been closed.'
            ];
            
            $notification_message = $status_messages[$new_status];
            if ($admin_notes) {
                $notification_message .= " Admin notes: {$admin_notes}";
            }
            
            create_notification(
                $report['reported_by'],
                'maintenance_update',
                'Maintenance Report Updated',
                $notification_message
            );
            
            // Log action
            log_action('MAINTENANCE_UPDATED', 'maintenance_reports', $report_id, 
                      "Status changed to: {$new_status}. Room: {$report['room_name']}");
            
            $_SESSION['success'] = "Maintenance report updated to '" . ucwords(str_replace('_', ' ', $new_status)) . "' successfully!";
        } else {
            $_SESSION['error'] = "Failed to update maintenance report.";
        }
        break;
        
    case 'assign_technician':
        // Assign maintenance to specific user (future feature)
        $technician_id = intval($_GET['tech_id'] ?? 0);
        
        if (!$technician_id) {
            $_SESSION['error'] = "Invalid technician ID.";
            header("Location: ../admin/maintenance_reports.php");
            exit();
        }
        
        $update = "UPDATE maintenance_reports 
                   SET resolved_by = ?,
                       status = 'in_progress'
                   WHERE report_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("ii", $technician_id, $report_id);
        
        if ($update_stmt->execute()) {
            // Get technician name for history
            $tech_query = "SELECT name FROM users WHERE user_id = ?";
            $tech_stmt = $conn->prepare($tech_query);
            $tech_stmt->bind_param("i", $technician_id);
            $tech_stmt->execute();
            $tech = $tech_stmt->get_result()->fetch_assoc();
            $tech_name = $tech['name'] ?? 'Technician';
            
            // Add to history
            addHistoryComment($conn, $report_id, 'in_progress', "Assigned to: {$tech_name}", $_SESSION['user_id']);
            
            log_action('MAINTENANCE_ASSIGNED', 'maintenance_reports', $report_id, 
                      "Assigned to technician ID: {$technician_id}");
            $_SESSION['success'] = "Maintenance report assigned successfully!";
        } else {
            $_SESSION['error'] = "Failed to assign report.";
        }
        break;
        
    case 'change_urgency':
        $new_urgency = $_GET['urgency'] ?? '';
        $old_urgency = $report['urgency'];
        
        // Validate urgency
        $valid_urgency = ['low', 'medium', 'high', 'critical'];
        if (!in_array($new_urgency, $valid_urgency)) {
            $_SESSION['error'] = "Invalid urgency level.";
            header("Location: ../admin/maintenance_reports.php");
            exit();
        }
        
        $update = "UPDATE maintenance_reports SET urgency = ? WHERE report_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $new_urgency, $report_id);
        
        if ($update_stmt->execute()) {
            // Add to history
            addHistoryComment($conn, $report_id, $report['status'], "Urgency changed from {$old_urgency} to {$new_urgency}", $_SESSION['user_id']);
            
            // If changed to critical, mark room as under maintenance
            if ($new_urgency === 'critical') {
                $mark_room = "UPDATE rooms SET status = 'maintenance' WHERE room_id = ?";
                $mark_stmt = $conn->prepare($mark_room);
                $mark_stmt->bind_param("i", $report['room_id']);
                $mark_stmt->execute();
                
                // Notify users with upcoming bookings
                $upcoming_query = "SELECT DISTINCT user_id 
                                   FROM bookings 
                                   WHERE room_id = ? 
                                   AND booking_date >= CURDATE() 
                                   AND status = 'approved'";
                $upcoming_stmt = $conn->prepare($upcoming_query);
                $upcoming_stmt->bind_param("i", $report['room_id']);
                $upcoming_stmt->execute();
                $affected_users = $upcoming_stmt->get_result();
                
                while ($user = $affected_users->fetch_assoc()) {
                    create_notification(
                        $user['user_id'],
                        'system',
                        'Critical Maintenance Alert',
                        "Room '{$report['room_name']}' has a critical maintenance issue. Your booking may be affected."
                    );
                }
            }
            
            log_action('MAINTENANCE_URGENCY_CHANGED', 'maintenance_reports', $report_id, 
                      "Urgency changed from {$old_urgency} to: {$new_urgency}");
            $_SESSION['success'] = "Urgency level updated to '{$new_urgency}' successfully!";
        } else {
            $_SESSION['error'] = "Failed to update urgency.";
        }
        break;
        
    case 'delete':
        // Only allow deletion if status is 'closed'
        if ($report['status'] !== 'closed') {
            $_SESSION['error'] = "Can only delete closed maintenance reports. Please close it first.";
            header("Location: ../admin/maintenance_reports.php");
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete comments first
            $delete_comments = "DELETE FROM maintenance_report_comments WHERE report_id = ?";
            $del_comments_stmt = $conn->prepare($delete_comments);
            $del_comments_stmt->bind_param("i", $report_id);
            $del_comments_stmt->execute();
            
            // Delete the report
            $delete = "DELETE FROM maintenance_reports WHERE report_id = ?";
            $delete_stmt = $conn->prepare($delete);
            $delete_stmt->bind_param("i", $report_id);
            $delete_stmt->execute();
            
            $conn->commit();
            
            log_action('MAINTENANCE_DELETED', 'maintenance_reports', $report_id, 
                      "Deleted closed report for room: {$report['room_name']}");
            $_SESSION['success'] = "Maintenance report deleted successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed to delete report: " . $e->getMessage();
        }
        break;
        
    case 'reopen':
        // Reopen a closed report
        $update = "UPDATE maintenance_reports 
                   SET status = 'pending',
                       resolved_at = NULL,
                       resolved_by = NULL,
                       admin_notes = CONCAT(COALESCE(admin_notes, ''), '\n[REOPENED by {$_SESSION['name']} at ' . date('Y-m-d H:i:s') . ']')
                   WHERE report_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("i", $report_id);
        
        if ($update_stmt->execute()) {
            // Add to history
            addHistoryComment($conn, $report_id, 'pending', "Report reopened for further investigation", $_SESSION['user_id']);
            
            // Notify reporter
            create_notification(
                $report['reported_by'],
                'maintenance_update',
                'Maintenance Report Reopened',
                "Your maintenance report for {$report['room_name']} has been reopened for further investigation."
            );
            
            log_action('MAINTENANCE_REOPENED', 'maintenance_reports', $report_id, 
                      "Report reopened for room: {$report['room_name']}");
            $_SESSION['success'] = "Maintenance report reopened successfully!";
        } else {
            $_SESSION['error'] = "Failed to reopen report.";
        }
        break;
        
    case 'bulk_close':
        // Close multiple resolved reports at once
        $close_resolved = "UPDATE maintenance_reports 
                           SET status = 'closed'
                           WHERE status = 'resolved' 
                           AND resolved_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        if ($conn->query($close_resolved)) {
            $affected = $conn->affected_rows;
            
            // Add history entries for bulk closed reports (optional - can be skipped for performance)
            // This is commented out as it might be heavy for many reports
            // You can enable if needed
            
            log_action('MAINTENANCE_BULK_CLOSE', 'maintenance_reports', null, 
                      "Closed {$affected} resolved reports older than 7 days");
            $_SESSION['success'] = "Closed {$affected} resolved reports successfully!";
        } else {
            $_SESSION['error'] = "Failed to close reports.";
        }
        break;
        
    case 'add_note':
        // Add additional admin note without changing status
        $additional_note = $_GET['note'] ?? '';
        
        if (empty($additional_note)) {
            $_SESSION['error'] = "Note cannot be empty.";
            header("Location: ../admin/maintenance_reports.php");
            exit();
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $admin_name = $_SESSION['name'];
        $formatted_note = "[{$timestamp} - {$admin_name}] {$additional_note}";
        
        $update = "UPDATE maintenance_reports 
                   SET admin_notes = CONCAT(COALESCE(admin_notes, ''), '\n', ?)
                   WHERE report_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $formatted_note, $report_id);
        
        if ($update_stmt->execute()) {
            // Add to history as a comment
            addHistoryComment($conn, $report_id, $report['status'], "Note added: {$additional_note}", $_SESSION['user_id']);
            
            log_action('MAINTENANCE_NOTE_ADDED', 'maintenance_reports', $report_id, 
                      "Added note to report");
            $_SESSION['success'] = "Note added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add note.";
        }
        break;
        
    default:
        $_SESSION['error'] = "Invalid action.";
}

header("Location: ../admin/maintenance_reports.php");
exit();
?>