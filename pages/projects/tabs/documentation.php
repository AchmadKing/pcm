<?php
/**
 * Tab Dokumentasi Proyek - Project Cost Control (File Manager)
 * Sistem manajemen file proyek hierarkis dengan folder, preview, rename, dan upload file antrean.
 */

if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

// 1. Current Active Folder and Path Resolution
$currentFolderId = !empty($_GET['folder_id']) ? intval($_GET['folder_id']) : null;
$currentFolder = null;
$breadcrumbs = [];
$parentFolderId = null;

if ($currentFolderId) {
    $currentFolder = dbGetRow("SELECT * FROM project_document_folders WHERE id = ? AND project_id = ?", [$currentFolderId, $projectId]);
    if (!$currentFolder) {
        $currentFolderId = null;
    } else {
        $parentFolderId = $currentFolder['parent_id'];
        
        // Build breadcrumbs trail upwards
        $temp = $currentFolder;
        $breadcrumbs[] = $temp;
        $safety = 0;
        while (!empty($temp['parent_id']) && $safety < 20) {
            $safety++;
            $parent = dbGetRow("SELECT * FROM project_document_folders WHERE id = ? AND project_id = ?", [$temp['parent_id'], $projectId]);
            if ($parent) {
                $breadcrumbs[] = $parent;
                $temp = $parent;
            } else {
                break;
            }
        }
        $breadcrumbs = array_reverse($breadcrumbs);
    }
}

// 2. Project-wide stats (totals across entire project)
$docStats = dbGetRow("SELECT COUNT(*) as cnt, COALESCE(SUM(file_size), 0) as total_size FROM project_documents WHERE project_id = ?", [$projectId]);
$totalProjectFiles = (int)($docStats['cnt'] ?? 0);
$totalProjectBytes = (float)($docStats['total_size'] ?? 0);

$folderStats = dbGetRow("SELECT COUNT(*) as cnt FROM project_document_folders WHERE project_id = ?", [$projectId]);
$totalProjectFolders = (int)($folderStats['cnt'] ?? 0);

// 3. Subfolders inside current active folder
$folderParams = [$projectId];
if ($currentFolderId) {
    $folderWhere = "f.parent_id = ?";
    $folderParams[] = $currentFolderId;
} else {
    $folderWhere = "f.parent_id IS NULL";
}

$folders = dbGetAll("
    SELECT f.*, u.full_name as created_by_name,
           (SELECT COUNT(*) FROM project_documents WHERE folder_id = f.id) as file_count,
           (SELECT COUNT(*) FROM project_document_folders WHERE parent_id = f.id) as subfolder_count
    FROM project_document_folders f
    LEFT JOIN users u ON f.created_by = u.id
    WHERE f.project_id = ? AND {$folderWhere}
    ORDER BY f.name ASC
", $folderParams);

// 4. Documents inside current active folder
$docParams = [$projectId];
if ($currentFolderId) {
    $docWhere = "pd.folder_id = ?";
    $docParams[] = $currentFolderId;
} else {
    $docWhere = "pd.folder_id IS NULL";
}

$documents = dbGetAll("
    SELECT pd.*, u.full_name as uploaded_by_name, u.role as uploaded_by_role
    FROM project_documents pd
    LEFT JOIN users u ON pd.uploaded_by = u.id
    WHERE pd.project_id = ? AND {$docWhere}
    ORDER BY pd.created_at DESC
", $docParams);

$totalItemsInCurrentFolder = count($folders) + count($documents);

// Helper to format bytes
if (!function_exists('formatDocBytes')) {
    function formatDocBytes($bytes, $precision = 1) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// Helper to get file type details (icon, color, badge, previewable)
if (!function_exists('getDocTypeInfo')) {
    function getDocTypeInfo($filename, $mimeType = '') {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $info = [
            'ext' => strtoupper($ext),
            'icon' => 'mdi-file-outline',
            'color' => '#6c757d',
            'bg_color' => '#f8f9fa',
            'badge_class' => 'bg-secondary',
            'is_image' => false,
            'is_pdf' => false,
            'can_preview' => false
        ];
        
        switch ($ext) {
            case 'pdf':
                $info['icon'] = 'mdi-file-pdf-box';
                $info['color'] = '#dc3545';
                $info['bg_color'] = '#fde8e8';
                $info['badge_class'] = 'bg-danger';
                $info['is_pdf'] = true;
                $info['can_preview'] = true;
                break;
                
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'webp':
            case 'gif':
            case 'svg':
                $info['icon'] = 'mdi-file-image';
                $info['color'] = '#0d6efd';
                $info['bg_color'] = '#e7f1ff';
                $info['badge_class'] = 'bg-primary';
                $info['is_image'] = true;
                $info['can_preview'] = true;
                break;
                
            case 'doc':
            case 'docx':
            case 'rtf':
                $info['icon'] = 'mdi-file-word-box';
                $info['color'] = '#0288d1';
                $info['bg_color'] = '#e1f5fe';
                $info['badge_class'] = 'bg-info text-white';
                break;
                
            case 'xls':
            case 'xlsx':
            case 'csv':
                $info['icon'] = 'mdi-file-excel-box';
                $info['color'] = '#198754';
                $info['bg_color'] = '#e8f5e9';
                $info['badge_class'] = 'bg-success';
                break;
                
            case 'ppt':
            case 'pptx':
                $info['icon'] = 'mdi-file-powerpoint-box';
                $info['color'] = '#fd7e14';
                $info['bg_color'] = '#fff3e0';
                $info['badge_class'] = 'bg-warning text-dark';
                break;
                
            case 'dwg':
            case 'dxf':
                $info['icon'] = 'mdi-ruler-square-compass';
                $info['color'] = '#6f42c1';
                $info['bg_color'] = '#f3e8fd';
                $info['badge_class'] = 'bg-purple text-white';
                break;
                
            case 'zip':
            case 'rar':
            case '7z':
            case 'tar':
            case 'gz':
                $info['icon'] = 'mdi-folder-zip-outline';
                $info['color'] = '#d63384';
                $info['bg_color'] = '#fce4ec';
                $info['badge_class'] = 'bg-pink text-white';
                break;
                
            case 'txt':
            case 'json':
            case 'xml':
                $info['icon'] = 'mdi-file-document-outline';
                $info['color'] = '#495057';
                $info['bg_color'] = '#f8f9fa';
                $info['badge_class'] = 'bg-dark';
                break;
        }
        
        return $info;
    }
}
?>

<div class="project-documentation-wrapper">

    <!-- ======================================================== -->
    <!-- STATS OVERVIEW CARDS                                     -->
    <!-- ======================================================== -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid h-100 border shadow-none mb-0 bg-soft-primary">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm me-3 flex-shrink-0">
                            <span class="avatar-title rounded-circle bg-primary text-white font-size-22 shadow-sm">
                                <i class="mdi mdi-file-multiple-outline"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Total Dokumen Proyek</p>
                            <h4 class="mb-0 text-primary fw-bold"><?= number_format($totalProjectFiles) ?> <span class="font-size-13 fw-normal text-muted">File</span></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid h-100 border shadow-none mb-0 bg-soft-info">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm me-3 flex-shrink-0">
                            <span class="avatar-title rounded-circle bg-info text-white font-size-22 shadow-sm">
                                <i class="mdi mdi-server"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Total Kapasitas Terpakai</p>
                            <h4 class="mb-0 text-info fw-bold"><?= formatDocBytes($totalProjectBytes) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid h-100 border shadow-none mb-0 bg-soft-warning">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm me-3 flex-shrink-0">
                            <span class="avatar-title rounded-circle bg-warning text-white font-size-22 shadow-sm">
                                <i class="mdi mdi-folder-multiple"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Total Folder Proyek</p>
                            <h4 class="mb-0 text-warning fw-bold"><?= number_format($totalProjectFolders) ?> <span class="font-size-13 fw-normal text-muted">Folder</span></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid h-100 border shadow-none mb-0 bg-soft-success">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm me-3 flex-shrink-0">
                            <span class="avatar-title rounded-circle bg-success text-white font-size-22 shadow-sm">
                                <i class="mdi mdi-folder-open-outline"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Lokasi Aktif</p>
                            <h6 class="mb-0 text-dark fw-bold text-truncate" title="<?= $currentFolder ? sanitize($currentFolder['name']) : 'Berkas Utama (Root)' ?>">
                                <?= $currentFolder ? sanitize($currentFolder['name']) : 'Berkas Utama (Root)' ?>
                            </h6>
                            <small class="text-muted font-size-11"><?= count($folders) ?> folder &bull; <?= count($documents) ?> file</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- FILE MANAGER TOOLBAR & NAVIGATION BAR                    -->
    <!-- ======================================================== -->
    <div class="card border shadow-sm mb-4">
        <div class="card-body p-3">
            
            <!-- Breadcrumb Path & Folder Hierarchy -->
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pb-2 mb-3 border-bottom">
                <div class="d-flex align-items-center flex-wrap gap-1 fm-breadcrumb-trail">
                    <?php if ($currentFolderId): ?>
                    <a href="?id=<?= $projectId ?>&tab=documentation<?= $parentFolderId ? '&folder_id=' . $parentFolderId : '' ?>" class="btn btn-sm btn-outline-secondary py-1 px-2 me-2" title="Kembali ke folder sebelumnya">
                        <i class="mdi mdi-arrow-left me-1"></i> Ke Atas
                    </a>
                    <?php endif; ?>
                    
                    <a href="?id=<?= $projectId ?>&tab=documentation" class="fm-breadcrumb-item <?= !$currentFolderId ? 'active fw-bold text-primary' : 'text-muted' ?>">
                        <i class="mdi mdi-folder-home-outline me-1 font-size-16 text-primary"></i> Berkas Proyek
                    </a>
                    
                    <?php foreach ($breadcrumbs as $idx => $bc): 
                        $isLast = ($idx === count($breadcrumbs) - 1);
                    ?>
                        <span class="text-muted mx-1"><i class="mdi mdi-chevron-right font-size-14"></i></span>
                        <?php if ($isLast): ?>
                        <span class="fm-breadcrumb-item active fw-bold text-dark text-truncate" style="max-width: 220px;" title="<?= sanitize($bc['name']) ?>">
                            <i class="mdi mdi-folder-open text-warning me-1"></i> <?= sanitize($bc['name']) ?>
                        </span>
                        <?php else: ?>
                        <a href="?id=<?= $projectId ?>&tab=documentation&folder_id=<?= $bc['id'] ?>" class="fm-breadcrumb-item text-truncate text-muted" style="max-width: 180px;" title="<?= sanitize($bc['name']) ?>">
                            <i class="mdi mdi-folder text-warning me-1"></i> <?= sanitize($bc['name']) ?>
                        </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="text-muted font-size-12 d-flex align-items-center gap-1">
                    <span class="badge bg-light text-dark border font-size-12"><i class="mdi mdi-folder text-warning me-1"></i><?= count($folders) ?> Folder</span>
                    <span class="badge bg-light text-dark border font-size-12"><i class="mdi mdi-file-outline text-primary me-1"></i><?= count($documents) ?> File</span>
                </div>
            </div>

            <!-- Toolbar: Search, View Mode, Actions -->
            <div class="row g-2 align-items-center justify-content-between">
                
                <!-- Quick Search in current directory -->
                <div class="col-lg-5 col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="mdi mdi-magnify font-size-16"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" id="fmSearchInput" placeholder="Cari folder atau file di lokasi ini..." onkeyup="filterFileManager()">
                        <button class="btn btn-light border border-start-0" type="button" onclick="$('#fmSearchInput').val(''); filterFileManager();" title="Reset pencarian">
                            <i class="mdi mdi-close font-size-14 text-muted"></i>
                        </button>
                    </div>
                </div>

                <!-- Controls & Action Buttons -->
                <div class="col-lg-7 col-md-6 d-flex justify-content-md-end align-items-center gap-2 flex-wrap">
                    
                    <!-- View Switcher -->
                    <div class="btn-group" role="group" aria-label="View switch">
                        <button type="button" class="btn btn-outline-primary active" id="btnViewGrid" onclick="switchDocView('grid')" title="Tampilan Grid / Ikon">
                            <i class="mdi mdi-view-grid-outline font-size-15"></i> Grid
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btnViewTable" onclick="switchDocView('table')" title="Tampilan Rinci / Tabel">
                            <i class="mdi mdi-format-list-bulleted font-size-15"></i> Tabel
                        </button>
                    </div>

                    <?php if (hasPermission('documentation.upload')): ?>
                    <!-- New Folder Button -->
                    <button type="button" class="btn btn-outline-warning text-dark border-warning shadow-sm d-flex align-items-center fw-semibold" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                        <i class="mdi mdi-folder-plus font-size-18 me-1 text-warning"></i> + Folder Baru
                    </button>

                    <!-- Upload Documents Button -->
                    <button type="button" class="btn btn-primary shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="mdi mdi-cloud-upload font-size-18 me-1"></i> Upload Dokumen
                    </button>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- EMPTY STATE FOR DIRECTORY                                -->
    <!-- ======================================================== -->
    <?php if ($totalItemsInCurrentFolder === 0): ?>
    <div class="card border border-dashed text-center py-5 my-3 shadow-none bg-white">
        <div class="card-body">
            <div class="avatar-lg mx-auto mb-3">
                <div class="avatar-title bg-soft-primary text-primary rounded-circle fs-1 shadow-sm">
                    <i class="mdi mdi-folder-open-outline"></i>
                </div>
            </div>
            <h4 class="font-size-18 text-dark fw-bold">Folder Ini Masih Kosong</h4>
            <p class="text-muted font-size-14 mx-auto" style="max-width: 520px;">
                Belum ada berkas dokumen ataupun folder di lokasi ini. Anda dapat membuat folder baru untuk mengorganisir dokumen atau langsung mengunggah file.
            </p>
            <?php if (hasPermission('documentation.upload')): ?>
            <div class="d-flex justify-content-center gap-2 mt-3">
                <button type="button" class="btn btn-outline-warning text-dark border-warning px-3 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                    <i class="mdi mdi-folder-plus me-1 text-warning"></i> Buat Folder
                </button>
                <button type="button" class="btn btn-primary px-3 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="mdi mdi-cloud-upload me-1"></i> Upload Dokumen di Sini
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <!-- ======================================================== -->
    <!-- 1. GRID / TILES VIEW (DEFAULT)                           -->
    <!-- ======================================================== -->
    <div id="fmGridView" class="fm-view-section">
        
        <!-- FOLDERS SECTION -->
        <?php if (!empty($folders)): ?>
        <div class="mb-4" id="fmFolderGridSection">
            <h6 class="text-muted text-uppercase font-size-11 fw-bold mb-3 d-flex align-items-center">
                <i class="mdi mdi-folder-multiple-outline me-1 text-warning font-size-16"></i> 
                Folder (<span id="fmFolderCountText"><?= count($folders) ?></span>)
            </h6>
            
            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-4 g-3" id="fmFolderGridContainer">
                <?php foreach ($folders as $f): ?>
                <div class="col fm-item-folder" data-name="<?= strtolower(sanitize($f['name'])) ?>">
                    <div class="card border fm-folder-card h-100 shadow-none mb-0 transition-all bg-white">
                        <div class="card-body p-3 d-flex align-items-center justify-content-between">
                            
                            <a href="?id=<?= $projectId ?>&tab=documentation&folder_id=<?= $f['id'] ?>" class="d-flex align-items-center flex-grow-1 overflow-hidden text-decoration-none text-dark me-2">
                                <div class="fm-folder-icon-box me-3 flex-shrink-0">
                                    <i class="mdi mdi-folder font-size-36 text-warning"></i>
                                </div>
                                <div class="overflow-hidden">
                                    <h6 class="mb-1 text-truncate fw-bold font-size-14 folder-name-text" title="<?= sanitize($f['name']) ?>">
                                        <?= sanitize($f['name']) ?>
                                    </h6>
                                    <small class="text-muted font-size-11 d-block">
                                        <?= $f['file_count'] ?> file<?= $f['subfolder_count'] > 0 ? ', ' . $f['subfolder_count'] . ' folder' : '' ?>
                                    </small>
                                </div>
                            </a>

                            <div class="dropdown flex-shrink-0">
                                <button class="btn btn-sm btn-light text-muted p-1 rounded-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 30px; height: 30px;">
                                    <i class="mdi mdi-dots-vertical font-size-16"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                    <li>
                                        <a class="dropdown-item font-size-13 py-2" href="?id=<?= $projectId ?>&tab=documentation&folder_id=<?= $f['id'] ?>">
                                            <i class="mdi mdi-folder-open-outline me-2 text-primary"></i> Buka Folder
                                        </a>
                                    </li>
                                    <?php if (hasPermission('documentation.upload')): ?>
                                    <li>
                                        <a class="dropdown-item font-size-13 py-2" href="javascript:void(0);" onclick="openRenameFolderModal(<?= $f['id'] ?>, '<?= sanitize(addslashes($f['name'])) ?>')">
                                            <i class="mdi mdi-pencil-outline me-2 text-info"></i> Ubah Nama
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li>
                                        <a class="dropdown-item font-size-13 py-2 text-danger" href="javascript:void(0);" onclick="openDeleteFolderModal(<?= $f['id'] ?>, '<?= sanitize(addslashes($f['name'])) ?>', <?= $f['file_count'] + $f['subfolder_count'] ?>)">
                                            <i class="mdi mdi-trash-can-outline me-2"></i> Hapus Folder
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- FILES SECTION -->
        <?php if (!empty($documents)): ?>
        <div id="fmFileGridSection">
            <h6 class="text-muted text-uppercase font-size-11 fw-bold mb-3 d-flex align-items-center">
                <i class="mdi mdi-file-multiple-outline me-1 text-primary font-size-16"></i> 
                Berkas & Dokumen (<span id="fmFileCountText"><?= count($documents) ?></span>)
            </h6>

            <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-4 g-3" id="fmFileGridContainer">
                <?php foreach ($documents as $doc): 
                    $typeInfo = getDocTypeInfo($doc['filename'], $doc['file_type']);
                    $downloadUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'];
                    $previewUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'] . '&preview=1';
                ?>
                <div class="col fm-item-file" 
                     data-id="<?= $doc['id'] ?>"
                     data-title="<?= strtolower(sanitize($doc['title'])) ?>"
                     data-filename="<?= strtolower(sanitize($doc['original_name'])) ?>"
                     data-description="<?= strtolower(sanitize($doc['description'] ?? '')) ?>"
                     data-uploader="<?= strtolower(sanitize($doc['uploaded_by_name'] ?? '')) ?>">
                    
                    <div class="card border fm-file-card h-100 shadow-none mb-0 transition-all bg-white position-relative overflow-hidden">
                        
                        <!-- Top Type Accent Stripe -->
                        <div class="fm-file-accent" style="height: 3px; background-color: <?= $typeInfo['color'] ?>;"></div>
                        
                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                            
                            <div>
                                <!-- Header: Type Icon & Dropdown -->
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="fm-file-icon-box rounded-3 p-2 d-flex align-items-center justify-content-center" 
                                         style="background-color: <?= $typeInfo['bg_color'] ?>; width: 44px; height: 44px; cursor: pointer;"
                                         onclick="triggerFileClick(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', '<?= $downloadUrl ?>', <?= $typeInfo['can_preview'] ? 'true' : 'false' ?>, <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)"
                                         title="Klik untuk melihat / mengunduh">
                                        <i class="mdi <?= $typeInfo['icon'] ?> font-size-26" style="color: <?= $typeInfo['color'] ?>;"></i>
                                    </div>

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light text-muted p-1 rounded-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 28px; height: 28px;">
                                            <i class="mdi mdi-dots-vertical font-size-16"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                            <?php if ($typeInfo['can_preview']): ?>
                                            <li>
                                                <a class="dropdown-item font-size-13 py-2" href="javascript:void(0);" 
                                                   onclick="openDocPreview(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)">
                                                    <i class="mdi mdi-eye-outline me-2 text-primary"></i> Pratinjau
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li>
                                                <a class="dropdown-item font-size-13 py-2" href="<?= $downloadUrl ?>" download>
                                                    <i class="mdi mdi-download-outline me-2 text-success"></i> Unduh File
                                                </a>
                                            </li>
                                            <?php if (hasPermission('documentation.upload')): ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <a class="dropdown-item font-size-13 py-2" href="javascript:void(0);" 
                                                   onclick="openEditDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['description'] ?? '')) ?>')">
                                                    <i class="mdi mdi-pencil-outline me-2 text-info"></i> Ubah Nama / Catatan
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item font-size-13 py-2 text-danger" href="javascript:void(0);" 
                                                   onclick="openDeleteDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>')">
                                                    <i class="mdi mdi-trash-can-outline me-2"></i> Hapus File
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>

                                <!-- File Title (Clickable) -->
                                <h6 class="font-size-14 fw-bold text-dark mb-1 file-title-text line-clamp-2" 
                                    style="cursor: pointer;"
                                    onclick="triggerFileClick(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', '<?= $downloadUrl ?>', <?= $typeInfo['can_preview'] ? 'true' : 'false' ?>, <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)"
                                    title="<?= sanitize($doc['title']) ?>">
                                    <?= sanitize($doc['title']) ?>
                                </h6>

                                <!-- Original Filename -->
                                <p class="text-muted font-size-11 mb-2 text-truncate font-monospace" title="<?= sanitize($doc['original_name']) ?>">
                                    <i class="mdi mdi-paperclip me-1 text-secondary"></i><?= sanitize($doc['original_name']) ?>
                                </p>

                                <!-- Notes if present -->
                                <?php if (!empty($doc['description'])): ?>
                                <div class="p-2 rounded bg-light border-start border-2 border-primary mb-2">
                                    <p class="font-size-11 text-secondary mb-0 line-clamp-2" title="<?= sanitize($doc['description']) ?>">
                                        <?= nl2br(sanitize($doc['description'])) ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Footer Meta & Actions -->
                            <div class="pt-2 border-top mt-2">
                                <div class="d-flex justify-content-between align-items-center mb-2 font-size-11 text-muted">
                                    <span class="badge bg-light text-dark font-monospace"><?= $typeInfo['ext'] ?></span>
                                    <span><?= formatDocBytes($doc['file_size']) ?></span>
                                    <span><?= date('d/m/y', strtotime($doc['created_at'])) ?></span>
                                </div>

                                <div class="d-flex justify-content-between align-items-center gap-1">
                                    <?php if ($typeInfo['can_preview']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 flex-grow-1 font-size-12" 
                                            onclick="openDocPreview(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)"
                                            title="Lihat Pratinjau">
                                        <i class="mdi mdi-eye-outline me-1"></i> Preview
                                    </button>
                                    <?php endif; ?>
                                    
                                    <a href="<?= $downloadUrl ?>" class="btn btn-sm btn-soft-success py-1 px-2 <?= $typeInfo['can_preview'] ? '' : 'flex-grow-1' ?> font-size-12" download title="Unduh File">
                                        <i class="mdi mdi-download me-1"></i> Unduh
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ======================================================== -->
    <!-- 2. TABLE / LIST VIEW                                     -->
    <!-- ======================================================== -->
    <div id="fmTableView" class="fm-view-section d-none">
        <div class="card border shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 font-size-13" id="fmTable">
                        <thead class="table-light">
                            <tr>
                                <th width="45" class="text-center">#</th>
                                <th width="50" class="text-center">Ikon</th>
                                <th>Nama Berkas / Folder</th>
                                <th width="110">Ukuran</th>
                                <th width="90" class="text-center">Tipe</th>
                                <th>Pembuat / Pengunggah</th>
                                <th width="120">Tanggal</th>
                                <th width="130" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Folders in table -->
                            <?php 
                            $rowNum = 1;
                            foreach ($folders as $f): 
                            ?>
                            <tr class="fm-item-folder" data-name="<?= strtolower(sanitize($f['name'])) ?>">
                                <td class="text-center text-muted font-size-12"><?= $rowNum++ ?></td>
                                <td class="text-center">
                                    <i class="mdi mdi-folder font-size-24 text-warning"></i>
                                </td>
                                <td>
                                    <a href="?id=<?= $projectId ?>&tab=documentation&folder_id=<?= $f['id'] ?>" class="fw-bold text-dark text-decoration-none folder-name-text">
                                        <?= sanitize($f['name']) ?>
                                    </a>
                                    <small class="text-muted d-block font-size-11">
                                        <?= $f['file_count'] ?> file<?= $f['subfolder_count'] > 0 ? ', ' . $f['subfolder_count'] . ' folder' : '' ?>
                                    </small>
                                </td>
                                <td class="text-muted font-size-12"><?= $f['file_count'] ?> item</td>
                                <td class="text-center"><span class="badge bg-warning text-dark">Folder</span></td>
                                <td class="font-size-12"><?= sanitize($f['created_by_name'] ?: 'Sistem') ?></td>
                                <td class="font-size-12 text-muted"><?= date('d/m/Y', strtotime($f['created_at'])) ?></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="?id=<?= $projectId ?>&tab=documentation&folder_id=<?= $f['id'] ?>" class="btn btn-outline-primary" title="Buka Folder">
                                            <i class="mdi mdi-folder-open"></i>
                                        </a>
                                        <?php if (hasPermission('documentation.upload')): ?>
                                        <button type="button" class="btn btn-outline-info" onclick="openRenameFolderModal(<?= $f['id'] ?>, '<?= sanitize(addslashes($f['name'])) ?>')" title="Ubah Nama">
                                            <i class="mdi mdi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="openDeleteFolderModal(<?= $f['id'] ?>, '<?= sanitize(addslashes($f['name'])) ?>', <?= $f['file_count'] + $f['subfolder_count'] ?>)" title="Hapus">
                                            <i class="mdi mdi-trash-can"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <!-- Documents in table -->
                            <?php foreach ($documents as $doc): 
                                $typeInfo = getDocTypeInfo($doc['filename'], $doc['file_type']);
                                $downloadUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'];
                                $previewUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'] . '&preview=1';
                            ?>
                            <tr class="fm-item-file" 
                                data-id="<?= $doc['id'] ?>"
                                data-title="<?= strtolower(sanitize($doc['title'])) ?>"
                                data-filename="<?= strtolower(sanitize($doc['original_name'])) ?>"
                                data-description="<?= strtolower(sanitize($doc['description'] ?? '')) ?>"
                                data-uploader="<?= strtolower(sanitize($doc['uploaded_by_name'] ?? '')) ?>">
                                
                                <td class="text-center text-muted font-size-12"><?= $rowNum++ ?></td>
                                <td class="text-center">
                                    <div class="avatar-xs mx-auto d-flex align-items-center justify-content-center rounded" style="background-color: <?= $typeInfo['bg_color'] ?>;">
                                        <i class="mdi <?= $typeInfo['icon'] ?> font-size-20" style="color: <?= $typeInfo['color'] ?>;"></i>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark file-title-text" style="cursor: pointer;" onclick="triggerFileClick(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', '<?= $downloadUrl ?>', <?= $typeInfo['can_preview'] ? 'true' : 'false' ?>, <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)">
                                        <?= sanitize($doc['title']) ?>
                                    </div>
                                    <small class="text-muted font-monospace d-block font-size-11">
                                        <i class="mdi mdi-paperclip me-1"></i><?= sanitize($doc['original_name']) ?>
                                    </small>
                                    <?php if (!empty($doc['description'])): ?>
                                    <small class="text-secondary d-block text-truncate mt-1" style="max-width: 320px;" title="<?= sanitize($doc['description']) ?>">
                                        <i class="mdi mdi-information-outline me-1"></i><?= sanitize($doc['description']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td class="font-size-12 font-monospace"><?= formatDocBytes($doc['file_size']) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $typeInfo['badge_class'] ?>"><?= $typeInfo['ext'] ?></span>
                                </td>
                                <td class="font-size-12"><?= sanitize($doc['uploaded_by_name'] ?: 'Sistem') ?></td>
                                <td class="font-size-12 text-muted"><?= date('d/m/Y', strtotime($doc['created_at'])) ?></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($typeInfo['can_preview']): ?>
                                        <button type="button" class="btn btn-outline-primary" 
                                                onclick="openDocPreview(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)"
                                                title="Preview Dokumen">
                                            <i class="mdi mdi-eye"></i>
                                        </button>
                                        <?php endif; ?>

                                        <a href="<?= $downloadUrl ?>" class="btn btn-outline-success" download title="Download">
                                            <i class="mdi mdi-download"></i>
                                        </a>

                                        <?php if (hasPermission('documentation.upload')): ?>
                                        <button type="button" class="btn btn-outline-info" 
                                                onclick="openEditDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['description'] ?? '')) ?>')"
                                                title="Ubah Nama & Catatan">
                                            <i class="mdi mdi-pencil"></i>
                                        </button>

                                        <button type="button" class="btn btn-outline-danger" 
                                                onclick="openDeleteDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>')"
                                                title="Hapus">
                                            <i class="mdi mdi-trash-can"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- No Match Alert when filtering -->
    <div id="noMatchAlert" class="alert alert-warning text-center py-4 my-3 d-none">
        <i class="mdi mdi-filter-remove-outline font-size-28 text-warning d-block mb-1"></i>
        <h6 class="fw-bold mb-1">Tidak ada item yang cocok dengan kata kunci pencarian</h6>
        <p class="text-muted font-size-13 mb-0">Silakan periksa kembali ejaan kata kunci pencarian Anda.</p>
    </div>

    <?php endif; ?>

</div>

<!-- ======================================================== -->
<!-- MODAL: BUAT FOLDER BARU                                  -->
<!-- ======================================================== -->
<?php if (hasPermission('documentation.upload')): ?>
<div class="modal fade" id="createFolderModal" tabindex="-1" aria-labelledby="createFolderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title text-white font-size-16" id="createFolderModalLabel">
                    <i class="mdi mdi-folder-plus-outline me-2"></i> Buat Folder Baru
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>">
                <input type="hidden" name="action" value="create_document_folder">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="parent_id" value="<?= $currentFolderId ?? '' ?>">
                
                <div class="modal-body p-4">
                    <div class="alert alert-soft-primary py-2 px-3 font-size-12 mb-3">
                        <i class="mdi mdi-folder-outline me-1"></i> Target Lokasi: <strong><?= $currentFolder ? sanitize($currentFolder['name']) : 'Berkas Utama (Root)' ?></strong>
                    </div>
                    <div class="mb-3">
                        <label for="createFolderName" class="form-label fw-bold required">Nama Folder</label>
                        <input type="text" class="form-control" id="createFolderName" name="name" placeholder="Contoh: Gambar Arsitektur, Kontrak & SPK..." required autofocus>
                    </div>
                </div>
                
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-folder-plus me-1"></i> Buat Folder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: UBAH NAMA FOLDER                                  -->
<!-- ======================================================== -->
<div class="modal fade" id="renameFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-info text-white py-3">
                <h5 class="modal-title text-white font-size-16">
                    <i class="mdi mdi-folder-edit-outline me-2"></i> Ubah Nama Folder
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>">
                <input type="hidden" name="action" value="rename_document_folder">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="folder_id" id="renameFolderId">
                <input type="hidden" name="parent_id" value="<?= $currentFolderId ?? '' ?>">
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="renameFolderName" class="form-label fw-bold required">Nama Folder Baru</label>
                        <input type="text" class="form-control" id="renameFolderName" name="name" required>
                    </div>
                </div>
                
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="mdi mdi-check me-1"></i> Simpan Nama
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: HAPUS FOLDER                                      -->
<!-- ======================================================== -->
<div class="modal fade" id="deleteFolderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title text-white mb-0"><i class="mdi mdi-trash-can-outline me-1"></i> Hapus Folder</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>">
                <input type="hidden" name="action" value="delete_document_folder">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="folder_id" id="deleteFolderId">
                <input type="hidden" name="return_folder_id" value="<?= $currentFolderId ?? '' ?>">
                
                <div class="modal-body text-center p-4">
                    <i class="mdi mdi-alert-circle-outline text-danger font-size-48 d-block mb-2"></i>
                    <h6 class="fw-bold mb-2">Hapus Folder: <span class="text-danger" id="deleteFolderNameText"></span>?</h6>
                    <div class="alert alert-warning py-2 font-size-12 mb-2 text-start">
                        <i class="mdi mdi-alert me-1"></i> <strong>Perhatian:</strong> Seluruh folder dan file di dalam folder ini (<span id="deleteFolderItemCount">0 item</span>) akan dihapus secara permanen dari server.
                    </div>
                    <p class="text-muted font-size-12 mb-0">Tindakan ini tidak dapat dibatalkan.</p>
                </div>
                
                <div class="modal-footer justify-content-center bg-light py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="mdi mdi-trash-can me-1"></i> Ya, Hapus Folder & Isinya
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: UPLOAD DOKUMEN PROYEK                             -->
<!-- ======================================================== -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            
            <div class="modal-header bg-primary text-white py-3 flex-shrink-0">
                <div class="d-flex align-items-center">
                    <i class="mdi mdi-cloud-upload font-size-22 me-2"></i>
                    <div>
                        <h5 class="modal-title text-white mb-0" id="uploadDocumentModalLabel">Upload Dokumen Proyek</h5>
                        <small class="text-white-50 font-size-12">
                            Lokasi: <strong><?= $currentFolder ? sanitize($currentFolder['name']) : 'Berkas Utama (Root)' ?></strong>
                        </small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" action="view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>" enctype="multipart/form-data" id="uploadDocForm" onsubmit="return submitDocUpload(event)">
                <input type="hidden" name="action" value="upload_project_documents">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="folder_id" value="<?= $currentFolderId ?? '' ?>">
                <input type="hidden" name="is_ajax" value="1">
                
                <div class="modal-body p-4">
                    
                    <div class="alert alert-info border-info py-2 mb-3">
                        <div class="d-flex align-items-center">
                            <i class="mdi mdi-information-outline font-size-20 me-2 flex-shrink-0"></i>
                            <div class="font-size-13">
                                <strong>Dokumentasi Proyek:</strong> Unggah file PDF, Word, Excel, Gambar Kerja (JPG/PNG), DWG, ZIP, dsb. Anda dapat memilih file berkali-kali tanpa menimpa antrean sebelumnya. Batas total upload per pengiriman adalah 40MB.
                            </div>
                        </div>
                    </div>

                    <!-- File Dropzone Input Area -->
                    <div class="mb-3">
                        <label class="form-label fw-bold required">Pilih File Dokumen</label>
                        <div class="doc-dropzone border border-2 border-primary border-dashed rounded-3 p-4 text-center bg-light position-relative" id="dropzoneBox" style="cursor: pointer;">
                            <input type="file" class="d-none" id="docFileInput" multiple onchange="handleFileInputChange(this)">
                            <div class="dropzone-content py-2">
                                <i class="mdi mdi-cloud-upload-outline font-size-48 text-primary d-block mb-2"></i>
                                <h6 class="fw-bold mb-1">Klik atau seret & lepas (drag & drop) file ke area ini</h6>
                                <p class="text-muted font-size-12 mb-3">Pilih file berkali-kali tanpa menimpa list &bull; Duplikat otomatis diberi nama (1), (2), dst.</p>
                                <button type="button" class="btn btn-sm btn-primary px-3 rounded-pill" onclick="$('#docFileInput').click()">
                                    <i class="mdi mdi-folder-open-outline me-1"></i> Telusuri File
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Files Queue Section -->
                    <div id="selectedFilesSection" class="mb-3 d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div>
                                <span class="fw-bold text-dark font-size-13">
                                    <i class="mdi mdi-format-list-numbered text-primary me-1"></i> Daftar Antrean Upload:
                                </span>
                                <span class="badge bg-primary rounded-pill ms-1" id="queueCountBadge">0 File</span>
                                <span class="badge bg-light text-dark border ms-1 font-monospace" id="queueSizeBadge">0 KB</span>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="$('#docFileInput').click()">
                                    <i class="mdi mdi-plus me-1"></i> Tambah File Lain
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearUploadQueue()">
                                    <i class="mdi mdi-trash-can-outline me-1"></i> Kosongkan List
                                </button>
                            </div>
                        </div>

                        <!-- Limit Warning Alert -->
                        <div id="queueSizeWarning" class="alert alert-warning py-2 px-3 font-size-12 d-none mb-2">
                            <i class="mdi mdi-alert me-1"></i> <strong>Perhatian:</strong> Total ukuran file mendekati atau melebihi batas 40MB. Mohon kurangi sebagian file sebelum mengunggah.
                        </div>

                        <div class="table-responsive border rounded-3 bg-white" style="max-height: 280px; overflow-y: auto;">
                            <table class="table table-sm table-hover align-middle mb-0 font-size-13">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th width="45" class="text-center">No.</th>
                                        <th width="55" class="text-center">Pratinjau</th>
                                        <th>Nama File & Dokumen</th>
                                        <th width="95" class="text-center">Ukuran</th>
                                        <th width="80" class="text-center">Tipe</th>
                                        <th width="115" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="uploadQueueTbody">
                                    <!-- Rendered dynamically via renderUploadQueue() -->
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted font-size-11 mt-1 d-block">
                            <i class="mdi mdi-information-outline me-1"></i> Klik thumbnail atau ikon pratinjau untuk melihat isi file. Klik <strong>Ubah Nama</strong> untuk mengganti nama dokumen.
                        </small>
                    </div>

                    <!-- Progress Bar for Upload -->
                    <div id="uploadProgressBarContainer" class="mb-3 d-none">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-semibold text-primary font-size-12" id="uploadProgressText">Mengunggah file...</span>
                            <span class="fw-bold font-size-12" id="uploadProgressPercent">0%</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>

                    <!-- Description / Notes -->
                    <div class="mb-0">
                        <label for="docDescription" class="form-label fw-bold">Catatan Referensi Dokumen <span class="text-muted fw-normal font-size-12">(Opsional)</span></label>
                        <textarea class="form-control" id="docDescription" name="description" rows="3" placeholder="Tuliskan catatan referensi, lingkup kerja terkait, nomor revisi, atau keterangan dokumen..."></textarea>
                    </div>

                </div>
                
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitUploadDoc">
                        <i class="mdi mdi-cloud-upload me-1"></i> Mulai Upload (<span id="btnSubmitCount">0 File</span>)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: RENAME QUEUE ITEM                                 -->
<!-- ======================================================== -->
<div class="modal fade" id="renameQueueModal" tabindex="-1" aria-labelledby="renameQueueModalLabel" aria-hidden="true" style="z-index: 1065;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title text-white mb-0" id="renameQueueModalLabel">
                    <i class="mdi mdi-pencil-outline me-1"></i> Ubah Nama File Dokumen
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <input type="hidden" id="renameQueueItemId">
                <label for="renameQueueInput" class="form-label fw-bold font-size-13 mb-1">Nama Dokumen Baru:</label>
                <div class="input-group mb-2">
                    <input type="text" class="form-control" id="renameQueueInput" placeholder="Masukkan nama dokumen...">
                    <span class="input-group-text font-monospace" id="renameQueueExt">.pdf</span>
                </div>
                <small class="text-muted font-size-11 d-block">
                    Nama ini akan disimpan sebagai judul file pada sistem dokumentasi.
                </small>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="saveRenamedQueueItem()">
                    <i class="mdi mdi-check me-1"></i> Simpan Nama
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: EDIT DOKUMEN (RENAME & CATATAN)                   -->
<!-- ======================================================== -->
<div class="modal fade" id="editDocumentModal" tabindex="-1" aria-labelledby="editDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-info text-white py-3">
                <h5 class="modal-title text-white font-size-16" id="editDocumentModalLabel">
                    <i class="mdi mdi-pencil-box-outline me-2"></i> Edit Informasi Dokumen
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" action="view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>" id="editDocForm">
                <input type="hidden" name="action" value="edit_project_document">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="doc_id" id="editDocId">
                <input type="hidden" name="folder_id" value="<?= $currentFolderId ?? '' ?>">
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="editDocTitle" class="form-label fw-bold required">Nama / Judul Dokumen</label>
                        <input type="text" class="form-control" id="editDocTitle" name="title" required>
                    </div>

                    <div class="mb-0">
                        <label for="editDocDescription" class="form-label fw-bold">Catatan & Keterangan Referensi</label>
                        <textarea class="form-control" id="editDocDescription" name="description" rows="4" placeholder="Tuliskan catatan referensi atau instruksi penggunaan file ini..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="mdi mdi-content-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: HAPUS DOKUMEN                                     -->
<!-- ======================================================== -->
<div class="modal fade" id="deleteDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title text-white mb-0"><i class="mdi mdi-trash-can-outline me-1"></i> Hapus Dokumen</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST" action="view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>" id="deleteDocForm">
                <input type="hidden" name="action" value="delete_project_document">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="doc_id" id="deleteDocId">
                <input type="hidden" name="folder_id" value="<?= $currentFolderId ?? '' ?>">
                
                <div class="modal-body text-center p-3">
                    <i class="mdi mdi-alert-circle-outline text-danger font-size-48 d-block mb-2"></i>
                    <h6 class="fw-bold mb-1" id="deleteDocTitle"></h6>
                    <p class="text-muted font-size-12 mb-0" id="deleteDocFilename"></p>
                    <p class="text-danger font-size-12 mt-2 mb-0">File fisik pada server akan dihapus secara permanen.</p>
                </div>
                
                <div class="modal-footer justify-content-center bg-light py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="mdi mdi-delete me-1"></i> Ya, Hapus File
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- MODAL: PREVIEW DOKUMEN (PDF & IMAGES)                    -->
<!-- ======================================================== -->
<div class="modal fade" id="previewDocumentModal" tabindex="-1" aria-labelledby="previewDocModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light py-2">
                <div class="overflow-hidden me-3">
                    <h6 class="modal-title fw-bold text-truncate" id="previewDocModalTitle">Preview Dokumen</h6>
                    <small class="text-muted font-monospace text-truncate d-block" id="previewDocFilename"></small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <a href="#" id="previewDocDownloadBtn" class="btn btn-sm btn-success py-1" download>
                        <i class="mdi mdi-download me-1"></i> Unduh File
                    </a>
                    <a href="#" id="previewDocNewTabBtn" target="_blank" class="btn btn-sm btn-outline-secondary py-1">
                        <i class="mdi mdi-open-in-new me-1"></i> Buka di Tab Baru
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            
            <div class="modal-body p-0 text-center bg-dark" id="previewDocBody" style="min-height: 520px; display: flex; align-items: center; justify-content: center;">
                <div class="spinner-border text-light" role="status">
                    <span class="visually-hidden">Memuat preview...</span>
                </div>
            </div>
            
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================== -->
<!-- CSS STYLES FOR FILE MANAGER & UPLOAD FIX                 -->
<!-- ======================================================== -->
<style>
/* ---------------------------------------------------- */
/* UPLOAD MODAL SCROLL & ZOOM RESILIENCY FIX            */
/* ---------------------------------------------------- */
#uploadDocumentModal .modal-dialog {
    max-height: calc(100vh - 2rem);
}
#uploadDocumentModal .modal-content {
    max-height: calc(100vh - 2rem);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
#uploadDocForm {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}
#uploadDocForm .modal-body {
    flex: 1 1 auto;
    overflow-y: auto !important;
    min-height: 0;
    max-height: calc(100vh - 12rem);
}
#uploadDocForm .modal-footer {
    flex-shrink: 0;
    position: sticky;
    bottom: 0;
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
    z-index: 1055;
}

/* ---------------------------------------------------- */
/* FILE MANAGER CARDS & ITEMS                           */
/* ---------------------------------------------------- */
.fm-folder-card {
    border-radius: 10px;
    border: 1px solid #e2e8f0 !important;
    transition: all 0.2s ease-in-out;
}
.fm-folder-card:hover {
    border-color: #f59e0b !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.12) !important;
}
.fm-folder-icon-box {
    line-height: 1;
    transition: transform 0.2s ease;
}
.fm-folder-card:hover .fm-folder-icon-box {
    transform: scale(1.08);
}
.fm-file-card {
    border-radius: 10px;
    border: 1px solid #e2e8f0 !important;
    transition: all 0.2s ease-in-out;
}
.fm-file-card:hover {
    border-color: #556ee6 !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(85, 110, 230, 0.12) !important;
}
.fm-file-icon-box {
    transition: transform 0.2s ease;
}
.fm-file-icon-box:hover {
    transform: scale(1.06);
}
.fm-breadcrumb-item {
    font-size: 13px;
    text-decoration: none;
    transition: color 0.15s ease;
}
.fm-breadcrumb-item:hover {
    color: #556ee6 !important;
}
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.doc-dropzone {
    transition: background-color 0.2s ease, border-color 0.2s ease;
}
.doc-dropzone:hover, .doc-dropzone.dragover {
    background-color: #eef2ff !important;
    border-color: #4361ee !important;
}
.bg-purple {
    background-color: #6f42c1 !important;
}
.bg-pink {
    background-color: #d63384 !important;
}
.bg-soft-primary {
    background-color: rgba(85, 110, 230, 0.08) !important;
}
.bg-soft-info {
    background-color: rgba(80, 165, 241, 0.08) !important;
}
.bg-soft-success {
    background-color: rgba(52, 195, 143, 0.08) !important;
}
.bg-soft-warning {
    background-color: rgba(241, 180, 76, 0.08) !important;
}
.queue-thumb-box {
    position: relative;
    cursor: pointer;
    overflow: hidden;
    border-radius: 6px;
    transition: transform 0.15s ease;
}
.queue-thumb-box:hover {
    transform: scale(1.05);
}
.queue-thumb-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.15s ease-in-out;
    border-radius: 6px;
}
.queue-thumb-box:hover .queue-thumb-overlay {
    opacity: 1;
}
.queue-item-title {
    max-width: 320px;
    display: inline-block;
    vertical-align: middle;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>

<!-- ======================================================== -->
<!-- JAVASCRIPT FOR FILE MANAGER & UPLOAD QUEUE               -->
<!-- ======================================================== -->
<script>
// Switch View between Grid and Table
function switchDocView(view) {
    if (view === 'grid') {
        $('#fmGridView').removeClass('d-none');
        $('#fmTableView').addClass('d-none');
        $('#btnViewGrid').addClass('active');
        $('#btnViewTable').removeClass('active');
        localStorage.setItem('pcm_doc_view', 'grid');
    } else {
        $('#fmGridView').addClass('d-none');
        $('#fmTableView').removeClass('d-none');
        $('#btnViewGrid').removeClass('active');
        $('#btnViewTable').addClass('active');
        localStorage.setItem('pcm_doc_view', 'table');
    }
}

// Filter File Manager items by search input
function filterFileManager() {
    const search = ($('#fmSearchInput').val() || '').toLowerCase().trim();
    let visibleFolders = 0;
    let visibleFiles = 0;

    // Filter folder items
    $('.fm-item-folder').each(function() {
        const name = $(this).data('name') || '';
        if (!search || name.includes(search)) {
            $(this).removeClass('d-none');
            visibleFolders++;
        } else {
            $(this).addClass('d-none');
        }
    });

    // Filter file items
    $('.fm-item-file').each(function() {
        const title = $(this).data('title') || '';
        const filename = $(this).data('filename') || '';
        const description = $(this).data('description') || '';
        const uploader = $(this).data('uploader') || '';

        if (!search || title.includes(search) || filename.includes(search) || description.includes(search) || uploader.includes(search)) {
            $(this).removeClass('d-none');
            visibleFiles++;
        } else {
            $(this).addClass('d-none');
        }
    });

    // Toggle folder/file section headers in Grid View
    if (visibleFolders === 0) {
        $('#fmFolderGridSection').addClass('d-none');
    } else {
        $('#fmFolderGridSection').removeClass('d-none');
    }

    if (visibleFiles === 0) {
        $('#fmFileGridSection').addClass('d-none');
    } else {
        $('#fmFileGridSection').removeClass('d-none');
    }

    // Show/hide no match alert
    const totalVisible = visibleFolders + visibleFiles;
    if (totalVisible === 0 && search.length > 0) {
        $('#noMatchAlert').removeClass('d-none');
    } else {
        $('#noMatchAlert').addClass('d-none');
    }
}

// Trigger file click (Preview if previewable, or download)
function triggerFileClick(id, title, filename, previewUrl, downloadUrl, canPreview, isImage, isPdf) {
    if (canPreview) {
        openDocPreview(id, title, filename, previewUrl, isImage, isPdf);
    } else {
        window.location.href = downloadUrl;
    }
}

// Open Rename Folder Modal
function openRenameFolderModal(id, currentName) {
    $('#renameFolderId').val(id);
    $('#renameFolderName').val(currentName);
    const m = new bootstrap.Modal(document.getElementById('renameFolderModal'));
    m.show();
    setTimeout(() => {
        $('#renameFolderName').focus().select();
    }, 400);
}

// Open Delete Folder Modal
function openDeleteFolderModal(id, name, itemCount) {
    $('#deleteFolderId').val(id);
    $('#deleteFolderNameText').text(name);
    $('#deleteFolderItemCount').text(itemCount + ' item');
    new bootstrap.Modal(document.getElementById('deleteFolderModal')).show();
}

// ========================================================
// UPLOAD QUEUE SYSTEM (ACCUMULATIVE, RENAME, PREVIEW)
// ========================================================
let uploadQueue = [];

// Helper: Format bytes in JS
function formatDocBytes(bytes, decimals = 1) {
    if (!bytes || bytes <= 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

// Helper: Get file extension
function getFileExt(filename) {
    return filename.slice((filename.lastIndexOf(".") - 1 >>> 0) + 2).toLowerCase();
}

// Helper: Get filename without extension
function getFileNameOnly(filename) {
    const idx = filename.lastIndexOf('.');
    return idx === -1 ? filename : filename.substring(0, idx);
}

// Helper: Get icon based on ext
function getFileIcon(ext) {
    switch (ext) {
        case 'pdf': return 'mdi-file-pdf-box text-danger';
        case 'jpg': case 'jpeg': case 'png': case 'webp': case 'gif': return 'mdi-file-image text-primary';
        case 'doc': case 'docx': return 'mdi-file-word-box text-info';
        case 'xls': case 'xlsx': case 'csv': return 'mdi-file-excel-box text-success';
        case 'ppt': case 'pptx': return 'mdi-file-powerpoint-box text-warning';
        case 'dwg': case 'dxf': return 'mdi-ruler-square-compass text-purple';
        case 'zip': case 'rar': case '7z': return 'mdi-folder-zip-outline text-pink';
        default: return 'mdi-file-document-outline text-secondary';
    }
}

// Resolve unique name for duplicate files: appends (1), (2), etc.
function resolveUniqueName(baseName, ext) {
    const existingNames = new Set(
        uploadQueue
            .filter(item => item.extension.toLowerCase() === ext.toLowerCase())
            .map(item => item.customName.toLowerCase())
    );

    if (!existingNames.has(baseName.toLowerCase())) {
        return { name: baseName, isAutoRenamed: false };
    }

    let counter = 1;
    let candidate = `${baseName}(${counter})`;
    while (existingNames.has(candidate.toLowerCase())) {
        counter++;
        candidate = `${baseName}(${counter})`;
    }
    return { name: candidate, isAutoRenamed: true };
}

// Add files to uploadQueue (Accumulative / Append)
function addFilesToQueue(fileList) {
    if (!fileList || fileList.length === 0) return;

    const maxFileSize = 40 * 1024 * 1024; // 40MB per file
    const forbidden = ['php', 'phtml', 'exe', 'dll', 'bat', 'sh', 'cmd', 'vbs', 'phar'];
    let addedCount = 0;
    let errors = [];

    Array.from(fileList).forEach(function(file) {
        const ext = getFileExt(file.name);
        if (forbidden.includes(ext)) {
            errors.push(`File "${file.name}" ditolak karena jenis file berisiko.`);
            return;
        }

        if (file.size > maxFileSize) {
            errors.push(`File "${file.name}" melebihi batas ukuran 40MB.`);
            return;
        }

        const rawBaseName = getFileNameOnly(file.name);
        const uniqueResolution = resolveUniqueName(rawBaseName, ext);

        const isImage = file.type.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'].includes(ext);
        const isPdf = file.type === 'application/pdf' || ext === 'pdf';
        const previewUrl = (isImage || isPdf) ? URL.createObjectURL(file) : null;
        const icon = getFileIcon(ext);

        uploadQueue.push({
            id: 'doc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8),
            file: file,
            originalName: file.name,
            customName: uniqueResolution.name,
            extension: ext,
            size: file.size,
            isImage: isImage,
            isPdf: isPdf,
            previewUrl: previewUrl,
            icon: icon,
            isAutoRenamed: uniqueResolution.isAutoRenamed,
            isRenamed: false
        });

        addedCount++;
    });

    if (errors.length > 0) {
        alert(errors.join('\n'));
    }

    renderUploadQueue();
}

// Handle native file input change
function handleFileInputChange(input) {
    addFilesToQueue(input.files);
    input.value = ''; // Reset immediately so user can select same files again
}

// Render upload queue table & counter
function renderUploadQueue() {
    const tbody = $('#uploadQueueTbody');
    const container = $('#selectedFilesSection');
    const countBadge = $('#queueCountBadge');
    const sizeBadge = $('#queueSizeBadge');
    const submitCount = $('#btnSubmitCount');
    const warning = $('#queueSizeWarning');

    if (!uploadQueue || uploadQueue.length === 0) {
        container.addClass('d-none');
        tbody.empty();
        countBadge.text('0 File');
        sizeBadge.text('0 B');
        submitCount.text('0 File');
        warning.addClass('d-none');
        return;
    }

    container.removeClass('d-none');
    const totalSize = uploadQueue.reduce((sum, item) => sum + item.size, 0);
    const count = uploadQueue.length;

    countBadge.text(count + ' File');
    sizeBadge.text(formatDocBytes(totalSize));
    submitCount.text(count + ' File');

    if (totalSize > 38 * 1024 * 1024) {
        warning.removeClass('d-none');
    } else {
        warning.addClass('d-none');
    }

    let html = '';
    uploadQueue.forEach(function(item, index) {
        let previewThumb = '';
        if (item.isImage && item.previewUrl) {
            previewThumb = `
                <div class="queue-thumb-box d-inline-block" onclick="previewQueueFile('${item.id}')" title="Klik untuk pratinjau gambar">
                    <img src="${item.previewUrl}" class="rounded border" style="width: 40px; height: 40px; object-fit: cover;" alt="preview">
                    <div class="queue-thumb-overlay"><i class="mdi mdi-eye text-white font-size-14"></i></div>
                </div>`;
        } else if (item.isPdf) {
            previewThumb = `
                <div class="queue-thumb-box d-inline-flex align-items-center justify-content-center rounded bg-soft-danger text-danger border border-danger" style="width: 40px; height: 40px;" onclick="previewQueueFile('${item.id}')" title="Klik untuk pratinjau PDF">
                    <i class="mdi mdi-file-pdf-box font-size-22"></i>
                    <div class="queue-thumb-overlay"><i class="mdi mdi-eye text-white font-size-14"></i></div>
                </div>`;
        } else {
            previewThumb = `
                <div class="queue-thumb-box d-inline-flex align-items-center justify-content-center rounded bg-light border" style="width: 40px; height: 40px;" onclick="previewQueueFile('${item.id}')" title="Klik untuk info file">
                    <i class="mdi ${item.icon} font-size-22"></i>
                    <div class="queue-thumb-overlay"><i class="mdi mdi-eye text-white font-size-14"></i></div>
                </div>`;
        }

        const typeBadge = item.isPdf 
            ? '<span class="badge bg-danger">PDF</span>' 
            : `<span class="badge bg-primary">${escapeHtml(item.extension.toUpperCase())}</span>`;

        html += `
            <tr id="queue-row-${item.id}">
                <td class="text-center fw-bold text-muted">${index + 1}</td>
                <td class="text-center">${previewThumb}</td>
                <td>
                    <div class="d-flex align-items-center flex-wrap">
                        <span class="fw-bold text-dark queue-item-title me-1" title="${escapeHtml(item.customName)}.${item.extension}">${escapeHtml(item.customName)}</span>
                        <span class="text-muted font-monospace font-size-12">.${item.extension}</span>
                        <button type="button" class="btn btn-link btn-sm text-primary p-0 ms-2 text-decoration-none" onclick="openRenameQueueModal('${item.id}')" title="Ubah nama dokumen">
                            <i class="mdi mdi-pencil font-size-12"></i> Ubah Nama
                        </button>
                    </div>
                    <div class="text-muted font-size-11 mt-1 text-truncate" style="max-width: 360px;">
                        <i class="mdi mdi-paperclip me-1"></i>Nama asli: <em>${escapeHtml(item.originalName)}</em>
                        ${item.isRenamed ? '<span class="badge bg-soft-info text-info ms-1"><i class="mdi mdi-pencil-check me-1"></i>Dinamai Ulang</span>' : ''}
                        ${item.isAutoRenamed ? '<span class="badge bg-soft-warning text-warning ms-1"><i class="mdi mdi-content-copy me-1"></i>Duplikat Otomatis</span>' : ''}
                    </div>
                </td>
                <td class="text-center font-monospace font-size-12">${formatDocBytes(item.size)}</td>
                <td class="text-center">${typeBadge}</td>
                <td class="text-center">
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-primary py-1 px-2" onclick="previewQueueFile('${item.id}')" title="Lihat Pratinjau">
                            <i class="mdi mdi-eye"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary py-1 px-2" onclick="openRenameQueueModal('${item.id}')" title="Ubah Nama">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger py-1 px-2" onclick="removeQueueItem('${item.id}')" title="Hapus dari list">
                            <i class="mdi mdi-trash-can-outline"></i>
                        </button>
                    </div>
                </td>
            </tr>`;
    });

    tbody.html(html);
}

// Remove single item from queue
function removeQueueItem(id) {
    const idx = uploadQueue.findIndex(item => item.id === id);
    if (idx !== -1) {
        const item = uploadQueue[idx];
        if (item.previewUrl) {
            URL.revokeObjectURL(item.previewUrl);
        }
        uploadQueue.splice(idx, 1);
        renderUploadQueue();
    }
}

// Clear all items in queue
function clearUploadQueue() {
    if (uploadQueue.length === 0) return;
    if (confirm('Kosongkan seluruh daftar antrean upload?')) {
        uploadQueue.forEach(item => {
            if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
        });
        uploadQueue = [];
        renderUploadQueue();
    }
}

// Open modal to rename a queued file
function openRenameQueueModal(id) {
    const item = uploadQueue.find(f => f.id === id);
    if (!item) return;

    $('#renameQueueItemId').val(item.id);
    $('#renameQueueInput').val(item.customName);
    $('#renameQueueExt').text('.' + item.extension);

    const renameModal = new bootstrap.Modal('#renameQueueModal');
    renameModal.show();

    setTimeout(() => {
        $('#renameQueueInput').focus().select();
    }, 400);
}

// Save renamed queued file
function saveRenamedQueueItem() {
    const id = $('#renameQueueItemId').val();
    const newName = $('#renameQueueInput').val().trim();

    if (!newName) {
        alert('Nama file tidak boleh kosong.');
        $('#renameQueueInput').focus();
        return;
    }

    const item = uploadQueue.find(f => f.id === id);
    if (item) {
        item.customName = newName;
        item.isRenamed = true;
        renderUploadQueue();
        const modalEl = document.getElementById('renameQueueModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) modalInstance.hide();
    }
}

// Preview file directly from queue
function previewQueueFile(id) {
    const item = uploadQueue.find(f => f.id === id);
    if (!item) return;

    const title = item.customName + '.' + item.extension;
    const subtitle = `File Antrean &bull; Nama asli: ${escapeHtml(item.originalName)} &bull; ${formatDocBytes(item.size)}`;

    $('#previewDocModalTitle').text(title);
    $('#previewDocFilename').html(subtitle);
    $('#previewDocDownloadBtn').addClass('d-none'); // file not yet on server

    if (item.previewUrl) {
        $('#previewDocNewTabBtn').removeClass('d-none').attr('href', item.previewUrl);
    } else {
        $('#previewDocNewTabBtn').addClass('d-none');
    }

    const body = $('#previewDocBody');
    body.html('<div class="spinner-border text-light my-5" role="status"><span class="visually-hidden">Memuat...</span></div>');

    const previewModal = new bootstrap.Modal('#previewDocumentModal');
    previewModal.show();

    if (item.isImage && item.previewUrl) {
        body.html(`<img src="${item.previewUrl}" class="img-fluid rounded" style="max-height: 78vh; object-fit: contain; box-shadow: 0 4px 20px rgba(0,0,0,0.5);" alt="${escapeHtml(title)}">`);
    } else if (item.isPdf && item.previewUrl) {
        body.html(`<iframe src="${item.previewUrl}#toolbar=1" style="width: 100%; height: 78vh; border: none;" title="${escapeHtml(title)}"></iframe>`);
    } else {
        body.html(`
            <div class="text-light my-5 p-4 text-center">
                <i class="mdi ${item.icon} font-size-64 d-block mb-3"></i>
                <h5 class="text-white">${escapeHtml(title)}</h5>
                <p class="text-muted mb-2 font-monospace font-size-13">${escapeHtml(item.originalName)} (${formatDocBytes(item.size)})</p>
                <div class="alert alert-info border-info d-inline-block text-start font-size-13 mt-2" style="max-width: 480px;">
                    <i class="mdi mdi-information-outline me-1"></i> Pratinjau langsung di peramban hanya tersedia untuk file Gambar dan PDF. Dokumen ini tetap akan tersimpan dan dapat diunduh setelah diunggah ke server.
                </div>
            </div>
        `);
    }
}

// Submit Upload via AJAX with progress bar (NO CATEGORY NEEDED)
function submitDocUpload(e) {
    if (e) e.preventDefault();

    if (!uploadQueue || uploadQueue.length === 0) {
        alert('Silakan pilih minimal 1 file dokumen untuk diunggah.');
        return false;
    }

    const totalSize = uploadQueue.reduce((sum, item) => sum + item.size, 0);
    const maxAllowed = 40 * 1024 * 1024; // 40MB
    if (totalSize > maxAllowed) {
        alert('Total ukuran file melebihi batas 40MB (' + formatDocBytes(totalSize) + '). Mohon kurangi sebagian file sebelum mengunggah.');
        return false;
    }

    const btn = $('#btnSubmitUploadDoc');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Mengunggah...');

    $('#uploadProgressBarContainer').removeClass('d-none');
    $('#uploadProgressBar').css('width', '0%').text('0%');
    $('#uploadProgressPercent').text('0%');
    $('#uploadProgressText').text('Menyiapkan file...');

    const formData = new FormData();
    formData.append('action', 'upload_project_documents');
    formData.append('project_id', '<?= $projectId ?>');
    formData.append('folder_id', '<?= $currentFolderId ?? '' ?>');
    formData.append('is_ajax', '1');
    formData.append('description', $('#docDescription').val().trim());

    uploadQueue.forEach(function(item) {
        formData.append('documents[]', item.file);
        formData.append('titles[]', item.customName);
        formData.append('file_names[]', item.customName + '.' + item.extension);
    });

    $.ajax({
        url: 'view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        xhr: function() {
            const xhr = new window.XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(evt) {
                if (evt.lengthComputable) {
                    const percentComplete = Math.round((evt.loaded / evt.total) * 100);
                    $('#uploadProgressBar').css('width', percentComplete + '%');
                    $('#uploadProgressPercent').text(percentComplete + '%');
                    if (percentComplete < 100) {
                        $('#uploadProgressText').text('Mengunggah ke server: ' + percentComplete + '%');
                    } else {
                        $('#uploadProgressText').text('Menyimpan dokumen ke server...');
                    }
                }
            }, false);
            return xhr;
        },
        success: function(res) {
            if (res && res.success) {
                $('#uploadProgressText').text('Berhasil disimpan! Memuat halaman...');
                setTimeout(function() {
                    window.location.href = res.redirect || 'view.php?id=<?= $projectId ?>&tab=documentation<?= $currentFolderId ? '&folder_id='.$currentFolderId : '' ?>';
                }, 350);
            } else {
                $('#uploadProgressBarContainer').addClass('d-none');
                btn.prop('disabled', false).html('<i class="mdi mdi-cloud-upload me-1"></i> Mulai Upload (' + uploadQueue.length + ' File)');
                alert(res && res.message ? res.message.replace(/<br\s*[\/]?>/gi, "\n") : 'Gagal mengunggah dokumen.');
            }
        },
        error: function(xhr, status, error) {
            $('#uploadProgressBarContainer').addClass('d-none');
            btn.prop('disabled', false).html('<i class="mdi mdi-cloud-upload me-1"></i> Mulai Upload (' + uploadQueue.length + ' File)');
            let errorMsg = 'Terjadi kesalahan jaringan atau server saat mengunggah.';
            try {
                const json = JSON.parse(xhr.responseText);
                if (json && json.message) errorMsg = json.message.replace(/<br\s*[\/]?>/gi, "\n");
            } catch (e) {
                if (xhr.status === 413) {
                    errorMsg = 'Ukuran file melebihi batas server (413 Payload Too Large). Kurangi jumlah atau ukuran file.';
                }
            }
            alert(errorMsg);
        }
    });

    return false;
}

// Open Edit Document Modal
function openEditDocModal(id, title, description) {
    $('#editDocId').val(id);
    $('#editDocTitle').val(title);
    $('#editDocDescription').val(description);
    
    new bootstrap.Modal('#editDocumentModal').show();
}

// Open Delete Document Modal
function openDeleteDocModal(id, title, filename) {
    $('#deleteDocId').val(id);
    $('#deleteDocTitle').text(title);
    $('#deleteDocFilename').text(filename);
    new bootstrap.Modal('#deleteDocumentModal').show();
}

// Open Preview Modal (PDF / Image / Viewer for already uploaded documents)
function openDocPreview(docId, title, filename, previewUrl, isImage, isPdf) {
    $('#previewDocModalTitle').text(title);
    $('#previewDocFilename').text(filename);
    $('#previewDocDownloadBtn').removeClass('d-none').attr('href', '<?= $baseUrl ?>/pages/projects/download_doc.php?id=' + docId);
    $('#previewDocNewTabBtn').removeClass('d-none').attr('href', previewUrl);
    
    const body = $('#previewDocBody');
    body.html('<div class="spinner-border text-light my-5" role="status"><span class="visually-hidden">Memuat...</span></div>');
    
    const previewModal = new bootstrap.Modal('#previewDocumentModal');
    previewModal.show();
    
    if (isImage) {
        const img = new Image();
        img.onload = function() {
            body.html(`<img src="${previewUrl}" class="img-fluid rounded" style="max-height: 78vh; object-fit: contain; box-shadow: 0 4px 20px rgba(0,0,0,0.5);" alt="${escapeHtml(title)}">`);
        };
        img.onerror = function() {
            body.html(`<div class="text-light my-5"><i class="mdi mdi-alert-circle font-size-48 text-warning d-block mb-2"></i><p>Gagal memuat gambar preview.</p></div>`);
        };
        img.src = previewUrl;
    } else if (isPdf) {
        body.html(`<iframe src="${previewUrl}#toolbar=1" style="width: 100%; height: 78vh; border: none;" title="${escapeHtml(title)}"></iframe>`);
    } else {
        body.html(`
            <div class="text-light my-5 p-4">
                <i class="mdi mdi-file-document-outline font-size-64 text-info d-block mb-3"></i>
                <h5>${escapeHtml(title)}</h5>
                <p class="text-muted mb-3">${escapeHtml(filename)}</p>
                <a href="${previewUrl}" class="btn btn-primary" download>
                    <i class="mdi mdi-download me-1"></i> Unduh File untuk Membuka
                </a>
            </div>
        `);
    }
}

// Helper escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Document ready initializations
$(document).ready(function() {
    // Restore saved view preference
    const savedView = localStorage.getItem('pcm_doc_view');
    if (savedView === 'table') {
        switchDocView('table');
    }

    // Bind Drag & Drop listeners on dropzoneBox
    const dropzone = document.getElementById('dropzoneBox');
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }, false);
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                addFilesToQueue(dt.files);
            }
        }, false);
    }

    // Rename input submit on Enter key
    $(document).on('keypress', '#renameQueueInput', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            saveRenamedQueueItem();
        }
    });
});
</script>
