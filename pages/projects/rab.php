<?php
/**
 * RAB Page - Redirect to View Tab
 * RAB is now integrated as a tab in view.php
 */

// Get project ID and redirect
$projectId = $_GET['id'] ?? null;

if ($projectId) {
    header('Location: view.php?id=' . $projectId . '&tab=rab');
} else {
    header('Location: index.php');
}
exit;
