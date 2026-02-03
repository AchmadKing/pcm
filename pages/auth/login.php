<?php
/**
 * Login Page
 * PCM - Project Cost Management System
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi!';
    } else {
        $user = dbGetRow(
            "SELECT id, username, password, full_name, role FROM users WHERE username = ? AND is_active = 1",
            [$username]
        );
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            
            header('Location: ../../index.php');
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    }
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/pcm_project';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <title>Login | PCM - Project Cost Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Project Cost Management System" name="description" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/dist/assets/images/favicon.ico">
    
    <!-- Bootstrap Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <!-- Icons Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <!-- App Css-->
    <link href="<?= $baseUrl ?>/dist/assets/css/app.min.css" rel="stylesheet" type="text/css" />
</head>

<body class="auth-body-bg">
    <div>
        <div class="container-fluid p-0">
            <div class="row g-0">
                <div class="col-lg-4">
                    <div class="authentication-page-content p-4 d-flex align-items-center min-vh-100">
                        <div class="w-100">
                            <div class="row justify-content-center">
                                <div class="col-lg-9">
                                    <div>
                                        <div class="text-center">
                                            <div>
                                                <h2 class="text-primary">
                                                    <i class="mdi mdi-clipboard-text-outline"></i> PCM
                                                </h2>
                                            </div>
                                            <h4 class="font-size-18 mt-4">Selamat Datang!</h4>
                                            <p class="text-muted">Silakan login untuk melanjutkan ke sistem PCM.</p>
                                        </div>

                                        <?php if ($error): ?>
                                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                            <?= $error ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                        </div>
                                        <?php endif; ?>

                                        <div class="p-2 mt-5">
                                            <form method="POST" action="">
                                                <div class="mb-3 auth-form-group-custom mb-4">
                                                    <i class="ri-user-2-line auti-custom-input-icon"></i>
                                                    <label for="username" class="fw-semibold">Username</label>
                                                    <input type="text" class="form-control" id="username" name="username" 
                                                           placeholder="Masukkan username" required
                                                           value="<?= sanitize($_POST['username'] ?? '') ?>">
                                                </div>
                        
                                                <div class="mb-3 auth-form-group-custom mb-4">
                                                    <i class="ri-lock-2-line auti-custom-input-icon"></i>
                                                    <label for="password">Password</label>
                                                    <input type="password" class="form-control" id="password" name="password" 
                                                           placeholder="Masukkan password" required>
                                                </div>
                        
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" id="remember">
                                                    <label class="form-check-label" for="remember">Ingat saya</label>
                                                </div>

                                                <div class="mt-4 text-center">
                                                    <button class="btn btn-primary w-md waves-effect waves-light" type="submit">
                                                        <i class="mdi mdi-login"></i> Login
                                                    </button>
                                                </div>
                                            </form>
                                        </div>

                                        <div class="mt-5 text-center">
                                            <p class="text-muted mb-1">
                                                <small>Demo Login:</small>
                                            </p>
                                            <p class="text-muted mb-0">
                                                <small><strong>Admin:</strong> admin / password</small>
                                            </p>
                                            <p class="text-muted mb-3">
                                                <small><strong>Tim Lapangan:</strong> field_team / password</small>
                                            </p>
                                            <p>© <script>document.write(new Date().getFullYear())</script> PCM. 
                                               Project Cost Management System</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="authentication-bg" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="bg-overlay" style="opacity: 0.3;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT -->
    <script src="<?= $baseUrl ?>/dist/assets/libs/jquery/jquery.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/metismenu/metisMenu.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/node-waves/waves.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/js/app.js"></script>
</body>
</html>
