<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

$action = $_GET['action'] ?? '';
$user_id = intval($_GET['id'] ?? 0);

if (!$user_id || !$action) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: ../admin/manage_users.php");
    exit();
}

// Prevent admin from modifying their own account (except via profile)
if ($user_id === $_SESSION['user_id'] && in_array($action, ['delete', 'toggle_status'])) {
    $_SESSION['error'] = "You cannot delete or disable your own account!";
    header("Location: ../admin/manage_users.php");
    exit();
}

// Get user details
$query = "SELECT * FROM users WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: ../admin/manage_users.php");
    exit();
}

switch ($action) {
    case 'reset_password':
        // Reset password to default: student123
        $default_password = 'student123';
        $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
        
        $update = "UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($update_stmt->execute()) {
            // Create notification for user
            create_notification(
                $user_id,
                'system',
                'Password Reset',
                'Your password has been reset by an administrator. Please login with: student123 and change it immediately.'
            );
            
            // Log action
            log_action('PASSWORD_RESET', 'users', $user_id, "Admin reset password for user: {$user['name']}");
            
            $_SESSION['success'] = "Password reset successfully for {$user['name']}! New password: student123";
        } else {
            $_SESSION['error'] = "Failed to reset password.";
        }
        break;
        
    case 'toggle_status':
        // Toggle between active and inactive
        $new_status = ($user['status'] === 'active') ? 'inactive' : 'active';
        
        $update = "UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $new_status, $user_id);
        
        if ($update_stmt->execute()) {
            // If disabling, cancel all their pending bookings
            if ($new_status === 'inactive') {
                $cancel_bookings = "UPDATE bookings 
                                    SET status = 'cancelled', 
                                        admin_remarks = 'Cancelled due to account deactivation'
                                    WHERE user_id = ? AND status = 'pending'";
                $cancel_stmt = $conn->prepare($cancel_bookings);
                $cancel_stmt->bind_param("i", $user_id);
                $cancel_stmt->execute();
            }
            
            // Create notification
            $message = ($new_status === 'inactive') 
                ? 'Your account has been deactivated by an administrator. Please contact admin if you believe this is an error.'
                : 'Your account has been reactivated. You can now login and use the system.';
            
            create_notification(
                $user_id,
                'system',
                'Account Status Changed',
                $message
            );
            
            // Log action
            log_action('USER_STATUS_CHANGED', 'users', $user_id, "Status changed to: {$new_status}");
            
            $_SESSION['success'] = "User account {$new_status} successfully!";
        } else {
            $_SESSION['error'] = "Failed to change user status.";
        }
        break;
        
    case 'delete':
        // Prevent deletion of admin users (safety measure)
        if (in_array($user['role'], ['admin', 'super_admin'])) {
            $_SESSION['error'] = "Cannot delete admin accounts! Please change role first or contact super admin.";
            header("Location: ../admin/manage_users.php");
            exit();
        }
        
        // Check if user has upcoming bookings
        $booking_check = "SELECT COUNT(*) as count FROM bookings 
                          WHERE user_id = ? 
                          AND booking_date >= CURDATE() 
                          AND status = 'approved'";
        $check_stmt = $conn->prepare($booking_check);
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $booking_count = $check_stmt->get_result()->fetch_assoc()['count'];
        
        if ($booking_count > 0) {
            $_SESSION['error'] = "Cannot delete user: {$booking_count} upcoming approved bookings exist. Cancel them first or wait until bookings pass.";
            header("Location: ../admin/manage_users.php");
            exit();
        }
        
        // Start transaction for safe deletion
        $conn->begin_transaction();
        
        try {
            // Delete user's notifications
            $delete_notif = "DELETE FROM notifications WHERE user_id = ?";
            $notif_stmt = $conn->prepare($delete_notif);
            $notif_stmt->bind_param("i", $user_id);
            $notif_stmt->execute();
            
            // Delete user's past bookings
            $delete_bookings = "DELETE FROM bookings WHERE user_id = ?";
            $book_stmt = $conn->prepare($delete_bookings);
            $book_stmt->bind_param("i", $user_id);
            $book_stmt->execute();
            
            // Update maintenance reports (set reporter to NULL)
            $update_reports = "UPDATE maintenance_reports SET reported_by = NULL WHERE reported_by = ?";
            $rep_stmt = $conn->prepare($update_reports);
            $rep_stmt->bind_param("i", $user_id);
            $rep_stmt->execute();
            
            // Update system logs (set user_id to NULL)
            $update_logs = "UPDATE system_logs SET user_id = NULL WHERE user_id = ?";
            $log_stmt = $conn->prepare($update_logs);
            $log_stmt->bind_param("i", $user_id);
            $log_stmt->execute();
            
            // Finally delete the user
            $delete_user = "DELETE FROM users WHERE user_id = ?";
            $user_stmt = $conn->prepare($delete_user);
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Log action
            log_action('USER_DELETED', 'users', $user_id, "User '{$user['name']}' ({$user['email']}) deleted with all personal data");
            
            $_SESSION['success'] = "User '{$user['name']}' and all associated data deleted successfully!";
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $_SESSION['error'] = "Failed to delete user: " . $e->getMessage();
        }
        break;
        
    case 'change_role':
        $new_role = $_GET['role'] ?? '';
        
        // Validate role
        $valid_roles = ['student', 'lecturer', 'admin'];
        if (!in_array($new_role, $valid_roles)) {
            $_SESSION['error'] = "Invalid role value.";
            header("Location: ../admin/manage_users.php");
            exit();
        }
        
        $update = "UPDATE users SET role = ?, updated_at = NOW() WHERE user_id = ?";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bind_param("si", $new_role, $user_id);
        
        if ($update_stmt->execute()) {
            // Create notification
            create_notification(
                $user_id,
                'system',
                'Account Role Changed',
                "Your account role has been changed to: {$new_role}. Your permissions have been updated."
            );
            
            // Log action
            log_action('USER_ROLE_CHANGED', 'users', $user_id, "Role changed from {$user['role']} to {$new_role}");
            
            $_SESSION['success'] = "User role updated to '{$new_role}' successfully!";
        } else {
            $_SESSION['error'] = "Failed to change user role.";
        }
        break;
        
    case 'unlock_account':
        // Unlock account that was locked due to failed login attempts
        $unlock = "UPDATE users 
                   SET login_attempts = 0,
                       last_attempt_time = NULL,
                       account_locked_until = NULL
                   WHERE user_id = ?";
        $unlock_stmt = $conn->prepare($unlock);
        $unlock_stmt->bind_param("i", $user_id);
        
        if ($unlock_stmt->execute()) {
            // Create notification
            create_notification(
                $user_id,
                'system',
                'Account Unlocked',
                'Your account has been unlocked by an administrator. You can now login again.'
            );
            
            // Log action
            log_action('ACCOUNT_UNLOCKED', 'users', $user_id, "Admin unlocked account for: {$user['name']}");
            
            $_SESSION['success'] = "Account unlocked successfully for {$user['name']}!";
        } else {
            $_SESSION['error'] = "Failed to unlock account.";
        }
        break;
        
    case 'send_notification':
        // Quick way to send notification to user
        $title = $_GET['title'] ?? 'Admin Message';
        $message = $_GET['message'] ?? 'You have a message from the administrator.';
        
        if (create_notification($user_id, 'system', $title, $message)) {
            log_action('NOTIFICATION_SENT', 'notifications', null, "Admin sent notification to: {$user['name']}");
            $_SESSION['success'] = "Notification sent to {$user['name']} successfully!";
        } else {
            $_SESSION['error'] = "Failed to send notification.";
        }
        break;
        
    default:
        $_SESSION['error'] = "Invalid action.";
}

header("Location: ../admin/manage_users.php");
exit();
?>