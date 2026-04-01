<?php
// Database Configuration for FCSIT Room Booking System - Cloud Version
// Author: Chung Kai Jian
// Date: 2026

// Railway Environment Variables
// These are automatically pulled from the "Variables" tab in your Railway dashboard
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'fcsit_booking_system');
define('DB_PORT', getenv('MYSQLPORT') ?: '3306');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Create database connection
try {
    // Note: Added DB_PORT to the connection for Railway compatibility
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Timezone setting
date_default_timezone_set('Asia/Kuching');

// Helper function to sanitize input
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    
    // Only use real_escape_string if connection exists
    if (isset($conn) && $conn) {
        return $conn->real_escape_string($data);
    }
    
    return $data;
}

// Helper function to check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Helper function to check user role
function check_role($required_role) {
    if (!is_logged_in()) {
        return false;
    }
    
    if (is_array($required_role)) {
        return in_array($_SESSION['role'], $required_role);
    }
    
    return $_SESSION['role'] === $required_role;
}

// Helper function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

// Helper function to generate notification
function create_notification($user_id, $type, $title, $message) {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $type, $title, $message);
    
    return $stmt->execute();
}

// Helper function to log system actions
function log_action($action_type, $table_affected = null, $record_id = null, $description = null) {
    global $conn;
    
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action_type, table_affected, record_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $user_id, $action_type, $table_affected, $record_id, $description, $ip_address);
    
    return $stmt->execute();
}
?>
