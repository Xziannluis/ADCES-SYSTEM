<?php
/**
 * Helper to resolve a photo URL.
 * Prefers file on disk; falls back to DB BLOB endpoint.
 *
 * @param string $type       'teacher' or 'user'
 * @param int    $id         Record ID (teacher.id or user.id)
 * @param string $photo_path The filename stored in photo_path column (may be empty)
 * @param string $base       Relative path prefix to uploads/ from the calling script (e.g. '../')
 * @return string|null       URL to the photo, or null if none available
 */
function getPhotoUrl($type, $id, $photo_path, $base = '../') {
    // Determine the subfolder
    $subfolder = $type === 'teacher' ? 'teachers' : 'users';
    $disk_dir = $base . 'uploads/' . $subfolder . '/';
    
    // 1. Try file on disk first
    if (!empty($photo_path) && file_exists($disk_dir . $photo_path)) {
        return $disk_dir . $photo_path;
    }
    
    // 2. Fall back to DB BLOB serve endpoint
    return $base . 'includes/serve_photo.php?type=' . urlencode($type) . '&id=' . (int)$id;
}
