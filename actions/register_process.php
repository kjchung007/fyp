<?php
// File: actions/register_process.php
session_start();
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../register.php");
    exit();
}

// Get form data
$name = sanitize_input($_POST['name']);
$matric_no = sanitize_input($_POST['matric_no']);
$email = sanitize_input($_POST['email']);
$password = $_POST['password'];
$confirm_password = $_POST['confirm_password'];
$phone = sanitize_input($_POST['phone']);
$role = sanitize_input($_POST['role']);

// Validate inputs
if (empty($name) || empty($matric_no) || empty($email) || empty($password) || empty($confirm_password) || empty($phone) || empty($role)) {
    $_SESSION['error'] = "Please fill in all fields.";
    header("Location: ../register.php");
    exit();
}

// Validate email format (UNIMAS format)
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = "Please enter a valid email address.";
    header("Location: ../register.php");
    exit();
}

// Check if passwords match
if ($password !== $confirm_password) {
    $_SESSION['error'] = "Passwords do not match.";
    header("Location: ../register.php");
    exit();
}

// Check password length
if (strlen($password) < 8) {
    $_SESSION['error'] = "Password must be at least 8 characters long.";
    header("Location: ../register.php");
    exit();
}

// Check if email already exists
$check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$check_email->bind_param("s", $email);
$check_email->execute();
$check_email->store_result();

if ($check_email->num_rows > 0) {
    $_SESSION['error'] = "Email already registered.";
    header("Location: ../register.php");
    exit();
}

// Check if matric_no already exists
$check_matric = $conn->prepare("SELECT user_id FROM users WHERE matric_no = ?");
$check_matric->bind_param("s", $matric_no);
$check_matric->execute();
$check_matric->store_result();

if ($check_matric->num_rows > 0) {
    $_SESSION['error'] = "Matric number already registered.";
    header("Location: ../register.php");
    exit();
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Default status for new users
$status = 'active';
$updated_at = date('Y-m-d H:i:s'); // Current timestamp

// Insert new user - include all required columns based on your table structure
$stmt = $conn->prepare("INSERT INTO users (name, email, password, role, matric_no, phone, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
$stmt->bind_param("ssssssss", $name, $email, $hashed_password, $role, $matric_no, $phone, $status, $updated_at);

if ($stmt->execute()) {
    $user_id = $stmt->insert_id;
    
    // Log the registration
    log_action('REGISTRATION', 'users', $user_id, "New user registered: $email");
    
    // Create welcome notification
    create_notification($user_id, 'system', 'Welcome!', 'Your account has been created successfully.');
    
    $_SESSION['success'] = "Account created successfully! You can now login.";
    header("Location: ../login.php");
} else {
    error_log("Registration error: " . $conn->error); // Log the error for debugging
    $_SESSION['error'] = "Registration failed. Please try again.";
    header("Location: ../register.php");
}

$stmt->close();
$conn->close();
exit();
?>
