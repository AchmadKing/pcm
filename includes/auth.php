<?php
/**
 * Authentication & Permission Functions
 * PCC - Project Cost Control System
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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
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
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_unset();
    session_destroy();
}

// ============================================
// PERMISSION FUNCTIONS (CORE PBAC)
// ============================================

/**
 * Load permissions from database for a given role
 * Reads from role_permissions table (real-time)
 * 
 * @param string $roleName
 * @param bool $clearCache Optional: force reload from database
 * @return array Associative array of permission_key => bool
 */
function loadUserPermissions($roleName, $clearCache = false) {
    // Static cache to avoid repeated DB queries within same request
    static $cache = [];
    if ($clearCache) {
        unset($cache[$roleName]);
    }
    if (!$clearCache && isset($cache[$roleName])) {
        return $cache[$roleName];
    }
    
    $permissions = [];
    $hasViewModeCol = true;
    
    try {
        $rows = dbGetAll("
            SELECT rp.permission_key, rp.is_allowed, rp.view_mode 
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            WHERE r.name = ?
        ", [$roleName]);
    } catch (Throwable $e) {
        $hasViewModeCol = false;
        try {
            $rows = dbGetAll("
                SELECT rp.permission_key, rp.is_allowed 
                FROM role_permissions rp
                JOIN roles r ON r.id = rp.role_id
                WHERE r.name = ?
            ", [$roleName]);
        } catch (Throwable $e2) {
            $rows = [];
        }
    }
    
    if (!empty($rows)) {
        foreach ($rows as $row) {
            if (!empty($row['is_allowed'])) {
                $permissions[$row['permission_key']] = true;
            }
            // Store view_mode for projects.view
            if ($row['permission_key'] === 'projects.view') {
                if ($hasViewModeCol && isset($row['view_mode']) && $row['view_mode'] !== null) {
                    $permissions['_view_mode'] = !empty($row['is_allowed']) ? $row['view_mode'] : 'none';
                } else {
                    $permissions['_view_mode'] = !empty($row['is_allowed']) ? ($roleName === 'field_team' ? 'assigned' : 'all') : 'none';
                }
            }
        }
    } else {
        // Fallback only if no permissions are configured in the DB yet for super_admin
        if ($roleName === 'super_admin') {
            $defaultKeys = [
                'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
                'projects.lock_request',
                'rab.view', 'rab.edit', 'rap.view', 'rap.edit',
                'requests.view', 'requests.create', 'requests.approve', 'requests.delete',
                'reports.view', 'reports.export',
                'master_data.view', 'master_data.edit',
                'documentation.view', 'documentation.upload',
                'admin.roles', 'admin.users'
            ];
            foreach ($defaultKeys as $key) {
                $permissions[$key] = true;
            }
            $permissions['_view_mode'] = 'all';
        }
    }
    
    // If projects.view is not set at all, default to none
    if (!isset($permissions['_view_mode'])) {
        $permissions['_view_mode'] = ($roleName === 'super_admin' && empty($rows)) ? 'all' : 'none';
    }
    
    $cache[$roleName] = $permissions;
    return $permissions;
}

/**
 * Get the project view mode for the current user
 * Returns: 'all' (see all projects), 'assigned' (only assigned), 'none' (no access)
 * 
 * @return string 'all', 'assigned', or 'none'
 */
function getProjectViewMode() {
    $role = $_SESSION['user_role'] ?? '';
    if (empty($role)) {
        return 'none';
    }
    
    $permissions = loadUserPermissions($role);
    
    // Users with projects.edit always get 'all' access
    if (!empty($permissions['projects.edit'])) {
        return 'all';
    }
    
    return $permissions['_view_mode'] ?? 'none';
}

/**
 * Check if the current user can access a specific project
 * Based on view_mode: 'all' = any project, 'assigned' = only if assigned, 'none' = blocked
 * 
 * @param int $projectId
 * @return bool
 */
function canAccessProject($projectId) {
    $viewMode = getProjectViewMode();
    
    if ($viewMode === 'all') {
        return true;
    }
    
    if ($viewMode === 'assigned') {
        try {
            $assignment = dbGetRow(
                "SELECT id FROM project_assignments WHERE project_id = ? AND user_id = ? AND is_active = 1",
                [$projectId, getCurrentUserId()]
            );
            return !empty($assignment);
        } catch (Throwable $e) {
            return false;
        }
    }
    
    return false; // 'none'
}

/**
 * Get users that can be assigned to projects
 * Returns users whose role has projects.view with view_mode = 'assigned'
 * These are users that need explicit assignment to access projects
 * 
 * @return array
 */
function getAssignableUsers() {
    try {
        return dbGetAll("
            SELECT DISTINCT u.id, u.username, u.full_name, u.role,
                   COALESCE(r.display_name, u.role) as role_display
            FROM users u
            JOIN roles r ON r.name = u.role COLLATE utf8mb4_unicode_ci
            JOIN role_permissions rp ON rp.role_id = r.id
            WHERE rp.permission_key = 'projects.view'
              AND rp.is_allowed = 1
              AND rp.view_mode = 'assigned'
              AND u.is_active = 1
            ORDER BY u.full_name ASC
        ");
    } catch (Throwable $e) {
        // Fallback query if view_mode column does not exist yet
        try {
            return dbGetAll("
                SELECT u.id, u.username, u.full_name, u.role,
                       COALESCE(r.display_name, u.role) as role_display
                FROM users u
                LEFT JOIN roles r ON r.name = u.role COLLATE utf8mb4_unicode_ci
                WHERE u.role = 'field_team' AND u.is_active = 1
                ORDER BY u.full_name ASC
            ");
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/**
 * Check if current user has a specific permission
 * Always reads fresh from DB (cached per-request via static variable in loadUserPermissions)
 * 
 * @param string $permissionKey e.g. 'projects.create', 'requests.approve'
 * @return bool
 */
function hasPermission($permissionKey) {
    $role = $_SESSION['user_role'] ?? '';
    if (empty($role)) {
        return false;
    }
    
    $permissions = loadUserPermissions($role);
    return isset($permissions[$permissionKey]) && !empty($permissions[$permissionKey]);
}

/**
 * Check if current user has any active permissions or project access
 * @return bool
 */
function hasAnyPermission() {
    if (isSuperAdmin()) {
        return true;
    }
    
    $role = $_SESSION['user_role'] ?? '';
    if (empty($role)) {
        return false;
    }
    
    $permissions = loadUserPermissions($role);
    
    // Check if project view mode allows access (not 'none')
    if (isset($permissions['_view_mode']) && $permissions['_view_mode'] !== 'none') {
        return true;
    }
    
    // Check if any explicit permission is granted
    foreach ($permissions as $key => $val) {
        if ($key[0] !== '_' && !empty($val)) {
            return true;
        }
    }
    
    return false;
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
    try {
        // Try to get from database first
        $roleData = dbGetRow("SELECT display_name FROM roles WHERE name = ?", [$role]);
        if ($roleData && !empty($roleData['display_name'])) {
            return $roleData['display_name'];
        }
    } catch (Throwable $e) {
        // Fallback to formatted name if database fails or table is missing
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
