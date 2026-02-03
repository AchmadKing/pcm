<?php
/**
 * Authentication Functions
 * PCM - Project Cost Management System
 */

session_start();

require_once __DIR__ . '/../config/database.php';

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require login - redirect to login page if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . getBaseUrl() . '/pages/auth/login.php');
        exit;
    }
}

/**
 * Check if current user is admin
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Require admin role
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . getBaseUrl() . '/pages/projects/index.php');
        exit;
    }
}

/**
 * Login user
 * @param string $username
 * @param string $password
 * @return bool
 */
function login($username, $password) {
    $user = dbGetRow(
        "SELECT id, username, password, full_name, role FROM users WHERE username = ? AND is_active = 1",
        [$username]
    );
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        return true;
    }
    
    return false;
}

/**
 * Logout user
 */
function logout() {
    session_unset();
    session_destroy();
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user full name
 * @return string|null
 */
function getCurrentUserName() {
    return $_SESSION['full_name'] ?? null;
}

/**
 * Get base URL
 * @return string
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host . '/pcm_project';
}
