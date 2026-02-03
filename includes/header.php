<?php
/**
 * Common Header with Sidebar
 * PCM - Project Cost Management System
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

requireLogin();

$currentUser = getCurrentUserName();
$currentRole = getCurrentUserRole();
$baseUrl = getBaseUrl();

// Determine current page for active menu highlighting
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <title><?= $pageTitle ?? 'Dashboard' ?> | PCM - Project Cost Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="Project Cost Management System" name="description" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/dist/assets/images/favicon.ico">
    
    <!-- Bootstrap Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
    <!-- Icons Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <!-- App Css -->
    <link href="<?= $baseUrl ?>/dist/assets/css/app.min.css" rel="stylesheet" type="text/css" />
    
    <!-- DataTables -->
    <link href="<?= $baseUrl ?>/dist/assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <!-- Select2 -->
    <link href="<?= $baseUrl ?>/dist/assets/libs/select2/css/select2.min.css" rel="stylesheet" type="text/css" />
    
    <style>
        .menu-arrow { transition: transform 0.2s; }
        .mm-active > a > .menu-arrow { transform: rotate(90deg); }
        .card { margin-bottom: 1.5rem; }
        .table th { white-space: nowrap; }
        .btn-action { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        .required::after { content: ' *'; color: red; }
    </style>
</head>

<body data-sidebar="dark">
    <div id="layout-wrapper">
        
        <!-- Header -->
        <header id="page-topbar">
            <div class="navbar-header">
                <div class="d-flex">
                    <div class="navbar-brand-box">
                        <a href="<?= $baseUrl ?>/index.php" class="logo">
                            <span class="logo-light fs-5 fw-semibold">
                                <i class="mdi mdi-clipboard-text-outline"></i> PCM
                            </span>
                            <span class="logo-sm fs-2">
                                <i class="mdi mdi-clipboard-text-outline"></i>
                            </span>
                        </a>
                    </div>
                    <button type="button" class="btn btn-sm px-3 font-size-24 header-item waves-effect" id="vertical-menu-btn">
                        <i class="mdi mdi-menu"></i>
                    </button>
                </div>

                <div class="d-flex">
                    <button type="button" class="btn header-item fs-4 rounded-end-0" id="light-dark-mode">
                        <i class="fas fa-moon align-middle"></i>
                    </button>
                    
                    <div class="dropdown d-none d-lg-inline-block ms-1">
                        <button type="button" class="btn header-item noti-icon waves-effect" data-toggle="fullscreen">
                            <i class="mdi mdi-arrow-expand-all noti-icon"></i>
                        </button>
                    </div>

                    <div class="dropdown notification-list d-inline-block user-dropdown">
                        <button type="button" class="btn header-item waves-effect" data-bs-toggle="dropdown">
                            <span class="d-none d-xl-inline-block ms-1"><?= sanitize($currentUser) ?></span>
                            <i class="mdi mdi-chevron-down d-none d-xl-inline-block"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end profile-dropdown">
                            <span class="dropdown-item text-muted">
                                <i class="mdi mdi-account-circle"></i> 
                                <?= $currentRole === 'admin' ? 'Administrator' : 'Tim Lapangan' ?>
                            </span>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger" href="<?= $baseUrl ?>/pages/auth/logout.php">
                                <i class="mdi mdi-power text-danger"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Left Sidebar -->
        <div class="vertical-menu">
            <div data-simplebar class="h-100">
                <div id="sidebar-menu">
                    <ul class="metismenu list-unstyled" id="side-menu">
                        <li class="menu-title">Menu</li>
                        
                        <!-- Dashboard - All Users -->
                        <li class="<?= $currentPage == 'index' && $currentDir == 'pcm_project' ? 'mm-active' : '' ?>">
                            <a href="<?= $baseUrl ?>/index.php" class="waves-effect">
                                <i class="mdi mdi-view-dashboard"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        
                        <?php if (isAdmin()): ?>
                        <?php endif; ?>
                        
                        <!-- Projects - All Users -->
                        <li class="<?= $currentDir == 'projects' ? 'mm-active' : '' ?>">
                            <a href="javascript:void(0);">
                                <i class="mdi mdi-briefcase"></i>
                                <span>Proyek<span class="float-end menu-arrow"><i class="mdi mdi-chevron-right"></i></span> </span>
                            </a>
                            <ul class="sub-menu">
                                <li class="<?= $currentPage == 'index' && $currentDir == 'projects' ? 'mm-active' : '' ?>">
                                    <a href="<?= $baseUrl ?>/pages/projects/index.php">Daftar Proyek</a>
                                </li>
                                <?php if (isAdmin()): ?>
                                <li class="<?= $currentPage == 'create' && $currentDir == 'projects' ? 'mm-active' : '' ?>">
                                    <a href="<?= $baseUrl ?>/pages/projects/create.php">Buat Proyek Baru</a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        
                        <!-- Requests -->
                        <li class="<?= $currentDir == 'requests' ? 'mm-active' : '' ?>">
                            <a href="javascript:void(0);">
                                <i class="mdi mdi-file-document-edit"></i>
                                <span>Pengajuan Dana<span class="float-end menu-arrow"><i class="mdi mdi-chevron-right"></i></span> </span>
                            </a>
                            <ul class="sub-menu">
                                <li class="<?= $currentPage == 'index' && $currentDir == 'requests' ? 'mm-active' : '' ?>">
                                    <a href="<?= $baseUrl ?>/pages/requests/index.php">Daftar Pengajuan</a>
                                </li>
                                <?php if (isAdmin()): ?>
                                <li class="<?= $currentPage == 'approval' ? 'mm-active' : '' ?>">
                                    <a href="<?= $baseUrl ?>/pages/requests/approval.php">Approval Center</a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        
                        <?php if (isAdmin()): ?>
                        <li class="<?= $currentDir == 'reports' ? 'mm-active' : '' ?>">
                            <a href="javascript:void(0);">
                                <i class="mdi mdi-chart-bar"></i>
                                <span>Laporan<span class="float-end menu-arrow"><i class="mdi mdi-chevron-right"></i></span> </span>
                            </a>
                            <ul class="sub-menu">
                                <li class="<?= $currentPage == 'dashboard' ? 'mm-active' : '' ?>">
                                    <a href="<?= $baseUrl ?>/pages/reports/dashboard.php">Dashboard</a>
                                </li>
                                <li class="<?= $currentPage == 'export' ? 'mm-active' : '' ?>">
                                    <a href="<?= $baseUrl ?>/pages/reports/export.php">Export RAB</a>
                                </li>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="page-content">
                <div class="container-fluid">
                    <?= renderFlash() ?>
