<?php
session_start();
require_once '../config.php';

if (!is_logged_in() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../report_issue.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$room_id = intval($_POST['room_id']);
$facility_id = !empty($_POST['facility_id']) ? intval($_POST['facility_id']) : null;
$issue_type = sanitize_input($_POST['issue_type']);
$urgency = sanitize_input($_POST['urgency']);
$description = sanitize_input($_POST['description']);

// Validation
if (!$room_id || !$issue_type || !$urgency || !$description) {
    $_SESSION['error'] = "All required fields must be filled.";
    header("Location: ../report_issue.php");
    exit();
}

// Validate enum values
$valid_issues = ['equipment_fault', 'furniture_damage', 'cleanliness', 'other'];
$valid_urgency = ['low', 'medium', 'high', 'critical'];

if (!in_array($issue_type, $valid_issues) || !in_array($urgency, $valid_urgency)) {
    $_SESSION['error'] = "Invalid issue type or urgency level.";
    header("Location: ../report_issue.php");
    exit();
}

// Insert maintenance report
$query = "INSERT INTO maintenance_reports (room_id, facility_id, reported_by, issue_type, description, urgency, status) 
          VALUES (?, ?, ?, ?, ?, ?, 'pending')";

$stmt = $conn->prepare($query);
$stmt->bind_param("iiisss", $room_id, $facility_id, $user_id, $issue_type, $description, $urgency);

if ($stmt->execute()) {
    $report_id = $conn->insert_id();
    
    // If urgency is critical, update room status
    if ($urgency === 'critical') {
        $update_room = "UPDATE rooms SET status = 'maintenance' WHERE room_id = ?";
        $update_stmt = $conn->prepare($update_room);
        $update_stmt->bind_param("i", $room_id);
        $update_stmt->execute();
    }
    
    // Create notification for user
    create_notification(
        $user_id,
        'maintenance_update',
        'Maintenance Report Submitted',
        'Your maintenance report has been submitted. Our team will review it shortly.'
    );
    
    // Notify admin users
    $admin_query = "SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'";
    $admin_result = $conn->query($admin_query);
    while ($admin = $admin_result->fetch_assoc()) {
        create_notification(
            $admin['user_id'],
            'maintenance_update',
            'New Maintenance Report',
            "A new {$urgency} priority maintenance issue has been reported."
        );
    }
    
    // Log action
    log_action('MAINTENANCE_REPORT_CREATED', 'maintenance_reports', $report_id, "New maintenance report submitted");
    
    $_SESSION['success'] = "Maintenance report submitted successfully! Our team will review it shortly.";
} else {
    $_SESSION['error'] = "Failed to submit report. Please try again.";
}

header("Location: ../report_issue.php");
exit();
?>