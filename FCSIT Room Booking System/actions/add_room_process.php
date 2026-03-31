<?php
// File: actions/add_room_process.php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/add_room.php");
    exit();
}

// Get form data
$room_name = sanitize_input($_POST['room_name']);
$room_type = sanitize_input($_POST['room_type']);
$capacity = intval($_POST['capacity']);
$building = sanitize_input($_POST['building']);
$floor = sanitize_input($_POST['floor']);
$description = sanitize_input($_POST['description']);
$status = sanitize_input($_POST['status']);

// Validate inputs
if (empty($room_name) || empty($room_type) || empty($capacity) || empty($building) || empty($floor) || empty($status)) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header("Location: ../admin/add_room.php");
    exit();
}

// Handle image upload
$image_url = null;
if (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === UPLOAD_ERR_OK) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $file_type = $_FILES['room_image']['type'];
    $file_size = $_FILES['room_image']['size'];
    
    // Validate file type
    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['error'] = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        header("Location: ../admin/add_room.php");
        exit();
    }
    
    // Validate file size
    if ($file_size > $max_size) {
        $_SESSION['error'] = "File too large. Maximum size is 5MB.";
        header("Location: ../admin/add_room.php");
        exit();
    }
    
    // Generate unique filename
    $extension = pathinfo($_FILES['room_image']['name'], PATHINFO_EXTENSION);
    $filename = 'room_' . time() . '_' . uniqid() . '.' . $extension;
    $upload_dir = '../uploads/rooms/';
    
    // Create upload directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $target_file = $upload_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($_FILES['room_image']['tmp_name'], $target_file)) {
        $image_url = 'uploads/rooms/' . $filename;
        
        // Resize image (optional)
        require_once '../includes/image_resizer.php'; // You'll need to create this
        resizeImage($target_file, 800, 600);
    } else {
        $_SESSION['error'] = "Failed to upload image.";
        header("Location: ../admin/add_room.php");
        exit();
    }
}

// Insert room into database
$stmt = $conn->prepare("INSERT INTO rooms (room_name, room_type, capacity, building, floor, description, image_url, status, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
$stmt->bind_param("ssisssss", $room_name, $room_type, $capacity, $building, $floor, $description, $image_url, $status);

if ($stmt->execute()) {
    $room_id = $stmt->insert_id;
    // Save facilities
    if (!empty($_POST['facilities'])) {
        foreach ($_POST['facilities'] as $facility_id) {
            $facility_id = intval($facility_id);
            $qty = isset($_POST['facility_qty'][$facility_id]) ? intval($_POST['facility_qty'][$facility_id]) : 1;
            $condition = isset($_POST['facility_condition'][$facility_id]) 
                            ? sanitize_input($_POST['facility_condition'][$facility_id]) 
                            : 'good';

            $fac_stmt = $conn->prepare("
                INSERT INTO room_facilities (room_id, facility_id, quantity, condition_status)
                VALUES (?, ?, ?, ?)
            ");
            $fac_stmt->bind_param("iiis", $room_id, $facility_id, $qty, $condition);
            $fac_stmt->execute();
        }
    }
    
    // Log action
    log_action('ROOM_ADDED', 'rooms', $room_id, "New room added: {$room_name}");
    
    $_SESSION['success'] = "Room added successfully!";
    header("Location: ../admin/manage_rooms.php");
} else {
    $_SESSION['error'] = "Failed to add room: " . $conn->error;
    header("Location: ../admin/add_room.php");
}

$stmt->close();
$conn->close();
exit();
?>