<?php
/**
 * RAP Page - Redirect to View Tab
 * RAP is now integrated as a tab in view.php
 */

// Get project ID and redirect
$projectId = $_GET['id'] ?? null;

if ($projectId) {
    header('Location: view.php?id=' . $projectId . '&tab=rap');
} else {
    header('Location: index.php');
}
exit;
