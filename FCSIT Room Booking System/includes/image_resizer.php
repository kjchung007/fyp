<?php
// File: includes/image_resizer.php

function resizeImage($file_path, $max_width, $max_height) {
    if (!file_exists($file_path)) {
        return false;
    }
    
    $image_info = getimagesize($file_path);
    if (!$image_info) {
        return false;
    }
    
    list($width, $height, $type) = $image_info;
    
    // Check if resizing is needed
    if ($width <= $max_width && $height <= $max_height) {
        return true;
    }
    
    // Calculate new dimensions
    $ratio = $width / $height;
    if ($max_width / $max_height > $ratio) {
        $new_width = $max_height * $ratio;
        $new_height = $max_height;
    } else {
        $new_width = $max_width;
        $new_height = $max_width / $ratio;
    }
    
    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($file_path);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($file_path);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($file_path);
            break;
        default:
            return false;
    }
    
    // Create new image
    $destination = imagecreatetruecolor($new_width, $new_height);
    
    // Preserve transparency for PNG and GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($destination, imagecolorallocatealpha($destination, 0, 0, 0, 127));
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
    }
    
    // Resize image
    imagecopyresampled($destination, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // Save resized image
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($destination, $file_path, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($destination, $file_path, 9);
            break;
        case IMAGETYPE_GIF:
            imagegif($destination, $file_path);
            break;
    }
    
    // Clean up
    imagedestroy($source);
    imagedestroy($destination);
    
    return true;
}
?>