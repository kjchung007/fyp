<?php
session_start();
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php");
    exit();
}

$email = sanitize_input($_POST['email']);
$password = $_POST['password'];

if (empty($email) || empty($password)) {
    $_SESSION['error'] = "Please fill in all fields.";
    header("Location: ../login.php");
    exit();
}

// Query user
$query = "SELECT * FROM users WHERE email = ? AND status = 'active'";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Invalid email or password.";
    log_action('LOGIN_FAILED', 'users', null, "Failed login attempt for: $email");
    header("Location: ../login.php");
    exit();
}

$user = $result->fetch_assoc();

// Verify password
if (!password_verify($password, $user['password'])) {
    $_SESSION['error'] = "Invalid email or password.";
    log_action('LOGIN_FAILED', 'users', $user['user_id'], "Failed login attempt");
    header("Location: ../login.php");
    exit();
}

// Set session variables
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['name'] = $user['name'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role'];

// Log successful login
log_action('LOGIN_SUCCESS', 'users', $user['user_id'], "User logged in successfully");

// Redirect based on role
if ($user['role'] === 'admin') {
    header("Location: ../admin/dashboard.php");
} else {
    header("Location: ../dashboard.php");
}
exit();
?>