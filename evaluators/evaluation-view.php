<?php
// Backward-compatible shim:
// This project previously had two very similarly named pages:
// - evaluation-view.php
// - evaluation_view.php
// To avoid confusion (and broken bookmarks), this file now redirects to the canonical page.

require_once '../auth/session-check.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$qs = $id > 0 ? ('?id=' . $id) : '';

header('Location: view_evaluation.php' . $qs);
exit();
?>