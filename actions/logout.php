<?php
session_start();
require_once '../config.php';

if (isset($_SESSION['user_id'])) {
    log_action('LOGOUT', 'users', $_SESSION['user_id'], "User logged out");
}

// Destroy session
session_destroy();
header("Location: ../login.php");
exit();
?>