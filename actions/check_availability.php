<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['available' => false, 'message' => 'Not logged in']);
    exit;
}

$room_id = intval($_GET['room_id'] ?? 0);
$date = $_GET['date'] ?? '';
$start_time = $_GET['start_time'] ?? '';
$end_time = $_GET['end_time'] ?? '';

if (!$room_id || !$date || !$start_time || !$end_time) {
    echo json_encode(['available' => false, 'message' => 'Missing parameters']);
    exit;
}

// Check for conflicts - FCFS algorithm as per FYP requirements
$query = "SELECT COUNT(*) as conflicts 
          FROM bookings 
          WHERE room_id = ? 
          AND booking_date = ? 
          AND status IN ('pending', 'approved')
          AND (
              (start_time < ? AND end_time > ?) OR
              (start_time < ? AND end_time > ?) OR
              (start_time >= ? AND end_time <= ?)
          )";

$stmt = $conn->prepare($query);
$stmt->bind_param("isssssss", $room_id, $date, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result['conflicts'] > 0) {
    echo json_encode([
        'available' => false, 
        'message' => 'This time slot conflicts with existing bookings.'
    ]);
} else {
    echo json_encode([
        'available' => true, 
        'message' => 'Room is available for booking.'
    ]);
}
?>
