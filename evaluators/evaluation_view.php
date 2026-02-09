<?php
// Backward-compatible shim:
// The canonical evaluation view page is now `view_evaluation.php`.
// This file is kept so old links/bookmarks continue to work.

require_once '../auth/session-check.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$qs = $id > 0 ? ('?id=' . $id) : '';

header('Location: view_evaluation.php' . $qs);
exit();
?>
