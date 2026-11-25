<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // Fungsi getBaseUrl() datang dari sini

$baseUrl = getBaseUrl(); 

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ' . $baseUrl . '/index.php');
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
        // login() dari auth.php otomatis load permissions ke session
        if (login($username, $password)) {
            header('Location: ../../index.php');
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <title>Login | PCC - Project Cost Control</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Project Cost Control System" name="description" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/dist/assets/images/favicon.ico">
    
    <!-- Bootstrap Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <!-- Icons Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <!-- App Css-->
    <link href="<?= $baseUrl ?>/dist/assets/css/app.min.css" rel="stylesheet" type="text/css" />

    <style>
        body.auth-body-bg {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            position: relative;
        }
        .bg-overlay-pattern {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.08) 0%, transparent 50%),
                        radial-gradient(circle at 80% 70%, rgba(0, 0, 0, 0.12) 0%, transparent 50%);
            pointer-events: none;
        }
        .login-card {
            width: 100%;
            max-width: 450px;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
            border: none;
            overflow: hidden;
            background: #ffffff;
            position: relative;
            z-index: 1;
        }
        .auth-form-group-custom .btn-toggle-password {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #74788d;
            cursor: pointer;
            font-size: 20px;
            padding: 0;
            z-index: 5;
        }
        .auth-form-group-custom .btn-toggle-password:hover {
            color: #556ee6;
        }
        .auth-form-group-custom .form-control {
            padding-right: 48px;
        }
    </style>
</head>

<body class="auth-body-bg">
    <div class="bg-overlay-pattern"></div>
    
    <div class="login-card card">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center">
                <div>
                    <h2 class="text-primary fw-bold">
                        <i class="mdi mdi-clipboard-text-outline"></i> PCC
                    </h2>
                </div>
                <h4 class="font-size-18 mt-3">Selamat Datang!</h4>
                <p class="text-muted">Silakan login untuk melanjutkan ke sistem PCC.</p>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-4" role="alert">
                <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="p-2 mt-4">
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
                        <button type="button" class="btn-toggle-password" id="togglePasswordBtn" title="Lihat kata sandi">
                            <i class="ri-eye-line" id="togglePasswordIcon"></i>
                        </button>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Ingat saya</label>
                    </div>

                    <div class="mt-4 text-center">
                        <button class="btn btn-primary w-100 waves-effect waves-light py-2 fw-semibold" type="submit">
                            <i class="mdi mdi-login me-1"></i> Login
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-4 text-center">
                <p class="mb-0 text-muted">© <script>document.write(new Date().getFullYear())</script> PCC. 
                   Project Cost Control System</p>
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

    <script>
        // Password Visibility Toggle
        const toggleBtn = document.getElementById('togglePasswordBtn');
        const pwdInput = document.getElementById('password');
        const pwdIcon = document.getElementById('togglePasswordIcon');

        if (toggleBtn && pwdInput && pwdIcon) {
            toggleBtn.addEventListener('click', function () {
                const isPassword = pwdInput.type === 'password';
                pwdInput.type = isPassword ? 'text' : 'password';
                if (isPassword) {
                    pwdIcon.classList.remove('ri-eye-line');
                    pwdIcon.classList.add('ri-eye-off-line');
                    this.setAttribute('title', 'Sembunyikan kata sandi');
                } else {
                    pwdIcon.classList.remove('ri-eye-off-line');
                    pwdIcon.classList.add('ri-eye-line');
                    this.setAttribute('title', 'Lihat kata sandi');
                }
            });
        }
    </script>
</body>
</html>
