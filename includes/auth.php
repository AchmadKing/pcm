<?php
/**
 * Authentication & Permission Functions
 * PCM - Project Cost Management System
 * 
 * Permission-Based Access Control (PBAC)
 * Akses ditentukan oleh permission yang dimiliki role, bukan hardcoded role name.
 * Super Admin otomatis memiliki semua permission.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// ============================================
// SESSION & LOGIN FUNCTIONS
// ============================================

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if current request is an AJAX request
 * @return bool
 */
function isAjaxRequest() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));
}

/**
 * Require login - redirect to login page if not logged in
 * Returns JSON for AJAX requests
 */
function requireLogin() {
    if (!isLoggedIn()) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Sesi telah berakhir. Silakan login kembali.']));
        }
        header('Location: ' . getBaseUrl() . '/pages/auth/login.php');
        exit;
    }
}

/**
 * Require a specific permission - redirect if not allowed
 * Returns JSON for AJAX requests
 * @param string $permissionKey
 */
function requirePermission($permissionKey) {
    requireLogin();
    if (!hasPermission($permissionKey)) {
        if (isAjaxRequest()) {
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk halaman ini.']));
        }
        setFlash('error', 'Anda tidak memiliki akses untuk halaman ini.');
        header('Location: ' . getBaseUrl() . '/index.php');
        exit;
    }
}

/**
 * Login user and load permissions into session
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
        
        // Load permissions dari database ke session
        $_SESSION['permissions'] = loadUserPermissions($user['role']);
        
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

// ============================================
// PERMISSION FUNCTIONS (CORE PBAC)
// ============================================

/**
 * Load permissions from database for a given role
 * Reads from list_akses JSON column in roles table (real-time)
 * Super admin gets all permissions automatically
 * 
 * @param string $roleName
 * @return array Associative array of permission_key => bool
 */
function loadUserPermissions($roleName) {
    // Static cache to avoid repeated DB queries within same request
    static $cache = [];
    if (isset($cache[$roleName])) {
        return $cache[$roleName];
    }
    
    $permissions = [];
    
    // Super admin always has all permissions
    if ($roleName === 'super_admin') {
        $defaultKeys = [
            'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
            'projects.lock_request',
            'rab.view', 'rab.edit', 'rap.view', 'rap.edit',
            'requests.view', 'requests.create', 'requests.approve',
            'reports.view', 'reports.export',
            'master_data.view', 'master_data.edit',
            'admin.roles', 'admin.users'
        ];
        foreach ($defaultKeys as $key) {
            $permissions[$key] = true;
        }
        $cache[$roleName] = $permissions;
        return $permissions;
    }
    
    // For other roles, load from role_permissions table
    $rows = dbGetAll("
        SELECT rp.permission_key, rp.is_allowed 
        FROM role_permissions rp
        JOIN roles r ON r.id = rp.role_id
        WHERE r.name = ?
    ", [$roleName]);
    
    foreach ($rows as $row) {
        if ($row['is_allowed']) {
            $permissions[$row['permission_key']] = true;
        }
    }
    
    $cache[$roleName] = $permissions;
    return $permissions;
}

/**
 * Check if current user has a specific permission
 * Super admin always returns true
 * Always reads fresh from DB (cached per-request via static variable)
 * 
 * @param string $permissionKey e.g. 'projects.create', 'requests.approve'
 * @return bool
 */
function hasPermission($permissionKey) {
    // Super admin bypass - always has all permissions
    if (isSuperAdmin()) {
        return true;
    }
    
    // Always load fresh from DB (static-cached per request in loadUserPermissions)
    $role = $_SESSION['user_role'] ?? '';
    if (empty($role)) {
        return false;
    }
    
    $permissions = loadUserPermissions($role);
    return isset($permissions[$permissionKey]) && $permissions[$permissionKey];
}


// ============================================
// ROLE CHECK FUNCTIONS (SIMPLIFIED)
// ============================================

/**
 * Check if current user is super admin
 * Ini adalah satu-satunya role check yang tetap berdasarkan role name,
 * karena super admin adalah role sistem yang tidak bisa dihapus.
 * 
 * @return bool
 */
function isSuperAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
}

// ============================================
// BACKWARD COMPATIBILITY FUNCTIONS
// Fungsi-fungsi ini tetap ada agar tidak breaking,
// tapi sekarang membaca dari permissions
// ============================================

/**
 * @deprecated Gunakan hasPermission() langsung
 */
function isAdmin() {
    return hasPermission('projects.create') && hasPermission('projects.edit') && hasPermission('requests.approve');
}

/**
 * @deprecated Gunakan hasPermission() langsung 
 */
function isProjectManager() {
    return hasPermission('requests.approve');
}

/**
 * @deprecated Gunakan hasPermission() langsung
 */
function isAdminOrPM() {
    return hasPermission('requests.approve');
}

/**
 * @deprecated Gunakan requirePermission() langsung
 */
function requireAdmin() {
    requirePermission('projects.edit');
}

/**
 * @deprecated Gunakan requirePermission() langsung
 */
function requireSuperAdmin() {
    requireLogin();
    if (!isSuperAdmin() && !hasPermission('admin.roles')) {
        setFlash('error', 'Anda tidak memiliki akses untuk halaman ini.');
        header('Location: ' . getBaseUrl() . '/index.php');
        exit;
    }
}

/**
 * @deprecated Gunakan requirePermission() langsung
 */
function requireAdminOrPM() {
    requirePermission('requests.approve');
}

// ============================================
// USER INFO FUNCTIONS
// ============================================

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
 * Get role display name from database
 * @param string $role
 * @return string
 */
function getRoleDisplayName($role) {
    // Try to get from database first
    $roleData = dbGetRow("SELECT display_name FROM roles WHERE name = ?", [$role]);
    if ($roleData && !empty($roleData['display_name'])) {
        return $roleData['display_name'];
    }
    
    // Fallback to formatted role name
    return ucfirst(str_replace('_', ' ', $role));
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

/**
 * Get base URL
 * @return string
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // Normalize paths to use forward slashes
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    
    // Find relative path from document root to project root case-insensitively
    $subDir = '';
    if (stripos($projectRoot, $docRoot) === 0) {
        $subDir = substr($projectRoot, strlen($docRoot));
    }
    
    // Clean up slashes
    $subDir = '/' . ltrim($subDir, '/');
    if ($subDir === '/') {
        $subDir = '';
    }
    
    return $protocol . '://' . $host . $subDir;
}
