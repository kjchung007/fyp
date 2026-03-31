<?php
// File: actions/update_room_process.php
session_start();
require_once '../config.php';

if (!is_logged_in() || !check_role(['admin', 'super_admin'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/manage_rooms.php");
    exit();
}

$room_id = intval($_POST['room_id']);
$current_image = $_POST['current_image'] ?? null;
$remove_image = isset($_POST['remove_image']);

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
    header("Location: ../admin/edit_room.php?id=" . $room_id);
    exit();
}

// Handle image upload/removal
$image_url = $current_image;

if ($remove_image) {
    // Delete old image file
    if ($image_url && file_exists("../" . $image_url)) {
        unlink("../" . $image_url);
    }
    $image_url = null;
} elseif (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === UPLOAD_ERR_OK) {
    // Delete old image if exists
    if ($image_url && file_exists("../" . $image_url)) {
        unlink("../" . $image_url);
    }
    
    // Upload new image
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    $file_type = $_FILES['room_image']['type'];
    $file_size = $_FILES['room_image']['size'];
    
    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['error'] = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        header("Location: ../admin/edit_room.php?id=" . $room_id);
        exit();
    }
    
    if ($file_size > $max_size) {
        $_SESSION['error'] = "File too large. Maximum size is 5MB.";
        header("Location: ../admin/edit_room.php?id=" . $room_id);
        exit();
    }
    
    $extension = pathinfo($_FILES['room_image']['name'], PATHINFO_EXTENSION);
    $filename = 'room_' . time() . '_' . uniqid() . '.' . $extension;
    $upload_dir = '../uploads/rooms/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $target_file = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES['room_image']['tmp_name'], $target_file)) {
        $image_url = 'uploads/rooms/' . $filename;
        
        // Resize image (optional)
        require_once '../includes/image_resizer.php';
        resizeImage($target_file, 800, 600);
    } else {
        $_SESSION['error'] = "Failed to upload image.";
        header("Location: ../admin/edit_room.php?id=" . $room_id);
        exit();
    }
}

// Update room in database
$stmt = $conn->prepare("UPDATE rooms SET room_name = ?, room_type = ?, capacity = ?, building = ?, floor = ?, description = ?, image_url = ?, status = ?, updated_at = NOW() WHERE room_id = ?");
$stmt->bind_param("ssisssssi", $room_name, $room_type, $capacity, $building, $floor, $description, $image_url, $status, $room_id);

if ($stmt->execute()) {

    // 🔥 STEP 1: Delete old facilities
    $delete_stmt = $conn->prepare("DELETE FROM room_facilities WHERE room_id = ?");
    $delete_stmt->bind_param("i", $room_id);
    $delete_stmt->execute();

    // 🔥 STEP 2: Insert new facilities
    if (!empty($_POST['facilities'])) {
        foreach ($_POST['facilities'] as $facility_id) {
            $facility_id = intval($facility_id);

            $qty = isset($_POST['facility_qty'][$facility_id]) 
                    ? intval($_POST['facility_qty'][$facility_id]) 
                    : 1;

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
    log_action('ROOM_UPDATED', 'rooms', $room_id, "Room details updated");

    $_SESSION['success'] = "Room updated successfully!";
    header("Location: ../admin/manage_rooms.php");

} else {
    $_SESSION['error'] = "Failed to update room: " . $conn->error;
    header("Location: ../admin/edit_room.php?id=" . $room_id);
}

$stmt->close();
$conn->close();
exit();
?>