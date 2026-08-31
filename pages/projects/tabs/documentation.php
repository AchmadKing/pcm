<?php
/**
 * Tab Dokumentasi Proyek - Project Cost Management
 * Tempat penyimpanan file referensi, gambar kerja, kontrak, spesifikasi teknis, dan dokumen arah proyek.
 */

if (!defined('DB_HOST')) {
    die('Direct access not permitted');
}

// Fetch all project documents
$documents = dbGetAll("
    SELECT pd.*, u.full_name as uploaded_by_name, u.role as uploaded_by_role
    FROM project_documents pd
    LEFT JOIN users u ON pd.uploaded_by = u.id
    WHERE pd.project_id = ?
    ORDER BY pd.created_at DESC
", [$projectId]);

$totalDocs = count($documents);
$totalBytes = array_sum(array_column($documents, 'file_size'));

// Predefined standard categories
$standardCategories = [
    'Gambar Kerja / Desain Arsitektur' => ['icon' => 'mdi-ruler-square-compass', 'color' => 'primary'],
    'Spesifikasi Teknis / RKS'          => ['icon' => 'mdi-file-cog-outline', 'color' => 'info'],
    'Dokumen Kontrak & SPK'            => ['icon' => 'mdi-file-certificate-outline', 'color' => 'warning'],
    'Perizinan & Dokumen Legal'        => ['icon' => 'mdi-shield-check-outline', 'color' => 'danger'],
    'Laporan & Berita Acara (BA)'      => ['icon' => 'mdi-clipboard-text-outline', 'color' => 'success'],
    'Referensi & Arah Proyek'          => ['icon' => 'mdi-compass-outline', 'color' => 'purple'],
    'Panduan & Standar Mutu'           => ['icon' => 'mdi-book-open-page-variant-outline', 'color' => 'secondary'],
    'Lain-lain'                        => ['icon' => 'mdi-folder-outline', 'color' => 'dark']
];

// Calculate category counts
$categoryCounts = [];
foreach ($documents as $doc) {
    $cat = $doc['category'] ?: 'Lain-lain';
    $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
}

// Helper to format bytes
function formatDocBytes($bytes, $precision = 1) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Helper to get file type details (icon, color, badge, previewable)
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
                                <i class="mdi mdi-folder-multiple-image"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Total Dokumen Proyek</p>
                            <h4 class="mb-0 text-primary fw-bold" id="statTotalDocs"><?= number_format($totalDocs) ?> <span class="font-size-13 fw-normal text-muted">File</span></h4>
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
                            <h4 class="mb-0 text-info fw-bold"><?= formatDocBytes($totalBytes) ?></h4>
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
                                <i class="mdi mdi-tag-multiple-outline"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Kategori Dokumen</p>
                            <h4 class="mb-0 text-success fw-bold"><?= count($categoryCounts) ?> <span class="font-size-13 fw-normal text-muted">Kategori</span></h4>
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
                                <i class="mdi mdi-clock-check-outline"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <p class="text-muted fw-semibold mb-1 text-truncate font-size-12">Dokumen Terbaru</p>
                            <h6 class="mb-0 text-dark fw-bold text-truncate" title="<?= !empty($documents[0]) ? sanitize($documents[0]['title']) : 'Belum ada dokumen' ?>">
                                <?= !empty($documents[0]) ? sanitize($documents[0]['title']) : '<em class="text-muted">Belum ada file</em>' ?>
                            </h6>
                            <?php if (!empty($documents[0])): ?>
                            <small class="text-muted font-size-11"><?= date('d M Y, H:i', strtotime($documents[0]['created_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- ACTION TOOLBAR & SEARCH FILTER                           -->
    <!-- ======================================================== -->
    <div class="card border shadow-sm mb-4">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center justify-content-between">
                
                <!-- Search Input -->
                <div class="col-lg-4 col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="mdi mdi-magnify font-size-16"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" id="docSearchInput" placeholder="Cari judul, nama file, keterangan, uploader..." onkeyup="filterDocuments()">
                        <button class="btn btn-light border border-start-0" type="button" onclick="$('#docSearchInput').val(''); filterDocuments();" title="Reset pencarian">
                            <i class="mdi mdi-close font-size-14 text-muted"></i>
                        </button>
                    </div>
                </div>

                <!-- Category Dropdown Filter for Mobile & Controls -->
                <div class="col-lg-8 col-md-6 d-flex justify-content-md-end align-items-center gap-2 flex-wrap">
                    
                    <!-- View Switcher Buttons (Grid vs Table) -->
                    <div class="btn-group" role="group" aria-label="View switch">
                        <button type="button" class="btn btn-outline-primary active" id="btnViewGrid" onclick="switchDocView('grid')" title="Tampilan Grid / Kartu">
                            <i class="mdi mdi-view-grid-outline"></i>
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btnViewTable" onclick="switchDocView('table')" title="Tampilan Tabel / Rinci">
                            <i class="mdi mdi-format-list-bulleted"></i>
                        </button>
                    </div>

                    <!-- Upload Button -->
                    <?php if (hasPermission('documentation.upload')): ?>
                    <button type="button" class="btn btn-primary shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="mdi mdi-cloud-upload font-size-18 me-1"></i> Upload Dokumen
                    </button>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Category Filter Pills -->
            <div class="d-flex flex-wrap gap-1 mt-3 pt-3 border-top" id="categoryFilterContainer">
                <button type="button" class="btn btn-sm btn-outline-secondary category-pill active px-3 rounded-pill" data-category="all" onclick="filterByCategory('all', this)">
                    Semua <span class="badge bg-secondary ms-1 rounded-pill"><?= $totalDocs ?></span>
                </button>
                <?php foreach ($standardCategories as $catName => $catMeta): 
                    $count = $categoryCounts[$catName] ?? 0;
                    if ($count > 0 || true): // show standard categories
                ?>
                <button type="button" class="btn btn-sm btn-outline-secondary category-pill px-3 rounded-pill" data-category="<?= sanitize($catName) ?>" onclick="filterByCategory('<?= sanitize(addslashes($catName)) ?>', this)">
                    <i class="mdi <?= $catMeta['icon'] ?> me-1 text-<?= $catMeta['color'] ?>"></i> <?= sanitize($catName) ?>
                    <?php if ($count > 0): ?>
                    <span class="badge bg-<?= $catMeta['color'] ?> ms-1 rounded-pill"><?= $count ?></span>
                    <?php endif; ?>
                </button>
                <?php endif; endforeach; ?>

                <?php 
                // Any other custom categories not in standard list
                foreach ($categoryCounts as $catName => $count):
                    if (!isset($standardCategories[$catName])):
                ?>
                <button type="button" class="btn btn-sm btn-outline-secondary category-pill px-3 rounded-pill" data-category="<?= sanitize($catName) ?>" onclick="filterByCategory('<?= sanitize(addslashes($catName)) ?>', this)">
                    <i class="mdi mdi-tag-outline me-1"></i> <?= sanitize($catName) ?>
                    <span class="badge bg-dark ms-1 rounded-pill"><?= $count ?></span>
                </button>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- EMPTY STATE                                              -->
    <!-- ======================================================== -->
    <?php if (empty($documents)): ?>
    <div class="card border border-dashed text-center py-5 my-3 shadow-none">
        <div class="card-body">
            <div class="avatar-lg mx-auto mb-3">
                <div class="avatar-title bg-soft-primary text-primary rounded-circle fs-1 shadow-sm">
                    <i class="mdi mdi-folder-open-outline"></i>
                </div>
            </div>
            <h4 class="font-size-18 text-dark fw-bold">Belum Ada Dokumentasi Proyek</h4>
            <p class="text-muted font-size-14 mx-auto" style="max-width: 540px;">
                Unggah dokumen referensi, gambar kerja, spesifikasi teknis, kontrak, atau panduan kerja agar seluruh tim proyek memiliki arah dan acuan yang selaras.
            </p>
            <?php if (hasPermission('documentation.upload')): ?>
            <button type="button" class="btn btn-primary px-4 py-2 mt-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                <i class="mdi mdi-cloud-upload font-size-18 me-1"></i> Upload Dokumen Pertama
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <!-- ======================================================== -->
    <!-- 1. GRID / CARDS VIEW (DEFAULT)                           -->
    <!-- ======================================================== -->
    <div id="docGridView" class="doc-view-section">
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-3" id="docCardsContainer">
            <?php foreach ($documents as $doc): 
                $typeInfo = getDocTypeInfo($doc['filename'], $doc['file_type']);
                $downloadUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'];
                $previewUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'] . '&preview=1';
                $catMeta = $standardCategories[$doc['category']] ?? ['icon' => 'mdi-tag-outline', 'color' => 'secondary'];
            ?>
            <div class="col doc-item-card" 
                 data-id="<?= $doc['id'] ?>"
                 data-title="<?= strtolower(sanitize($doc['title'])) ?>"
                 data-filename="<?= strtolower(sanitize($doc['original_name'])) ?>"
                 data-category="<?= sanitize($doc['category']) ?>"
                 data-description="<?= strtolower(sanitize($doc['description'] ?? '')) ?>"
                 data-uploader="<?= strtolower(sanitize($doc['uploaded_by_name'] ?? '')) ?>">
                
                <div class="card h-100 border shadow-sm doc-card position-relative transition-all overflow-hidden">
                    
                    <!-- Card Top Accent Bar -->
                    <div class="doc-card-accent" style="height: 4px; background-color: <?= $typeInfo['color'] ?>;"></div>
                    
                    <div class="card-body p-3 d-flex flex-column justify-content-between">
                        
                        <div>
                            <!-- Header: Icon, Category Badge & Size -->
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="d-flex align-items-center">
                                    <div class="doc-icon-wrapper rounded-3 p-2 me-2 d-flex align-items-center justify-content-center" 
                                         style="background-color: <?= $typeInfo['bg_color'] ?>; width: 44px; height: 44px;">
                                        <i class="mdi <?= $typeInfo['icon'] ?> font-size-26" style="color: <?= $typeInfo['color'] ?>;"></i>
                                    </div>
                                    <div>
                                        <span class="badge bg-soft-<?= $catMeta['color'] ?> text-<?= $catMeta['color'] ?> font-size-11 border border-<?= $catMeta['color'] ?> border-opacity-25 text-truncate" style="max-width: 150px;" title="<?= sanitize($doc['category']) ?>">
                                            <i class="mdi <?= $catMeta['icon'] ?> me-1 font-size-10"></i> <?= sanitize($doc['category']) ?>
                                        </span>
                                        <div class="text-muted font-size-11 mt-1">
                                            <span class="badge bg-light text-dark font-monospace"><?= $typeInfo['ext'] ?></span> &bull; <?= formatDocBytes($doc['file_size']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Document Title -->
                            <h6 class="font-size-14 fw-bold text-dark mb-1 doc-title line-clamp-2" title="<?= sanitize($doc['title']) ?>">
                                <?= sanitize($doc['title']) ?>
                            </h6>

                            <!-- Original Filename -->
                            <p class="text-muted font-size-12 mb-2 text-truncate font-monospace" title="<?= sanitize($doc['original_name']) ?>">
                                <i class="mdi mdi-paperclip me-1 text-secondary"></i><?= sanitize($doc['original_name']) ?>
                            </p>

                            <!-- Description / Notes -->
                            <div class="doc-notes-box p-2 rounded bg-light border-start border-3 border-primary mb-3">
                                <p class="font-size-12 text-secondary mb-0 line-clamp-3" title="<?= sanitize($doc['description'] ?? 'Tidak ada catatan khusus') ?>">
                                    <?= !empty($doc['description']) ? nl2br(sanitize($doc['description'])) : '<em class="text-muted font-size-11"><i class="mdi mdi-information-outline"></i> Tidak ada catatan referensi tambahan.</em>' ?>
                                </p>
                            </div>
                        </div>

                        <!-- Footer: Uploader & Action Buttons -->
                        <div class="pt-2 border-top">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted text-truncate font-size-11" style="max-width: 65%;" title="Diunggah oleh <?= sanitize($doc['uploaded_by_name'] ?: 'Sistem') ?>">
                                    <i class="mdi mdi-account-circle-outline me-1 text-primary"></i><strong><?= sanitize($doc['uploaded_by_name'] ?: 'Sistem') ?></strong>
                                </small>
                                <small class="text-muted font-size-11">
                                    <i class="mdi mdi-calendar-blank-outline me-1"></i><?= date('d/m/Y', strtotime($doc['created_at'])) ?>
                                </small>
                            </div>

                            <!-- Action Toolbar -->
                            <div class="d-flex justify-content-between align-items-center gap-1">
                                <div class="d-flex gap-1">
                                    <?php if ($typeInfo['can_preview']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2" 
                                            onclick="openDocPreview(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>', '<?= $previewUrl ?>', <?= $typeInfo['is_image'] ? 'true' : 'false' ?>, <?= $typeInfo['is_pdf'] ? 'true' : 'false' ?>)"
                                            title="Lihat / Preview Dokumen">
                                        <i class="mdi mdi-eye-outline me-1"></i> Preview
                                    </button>
                                    <?php endif; ?>

                                    <a href="<?= $downloadUrl ?>" class="btn btn-sm btn-soft-success py-1 px-2" title="Unduh File (<?= formatDocBytes($doc['file_size']) ?>)">
                                        <i class="mdi mdi-download"></i> Unduh
                                    </a>
                                </div>

                                <?php if (hasPermission('documentation.upload')): ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light text-muted p-1 rounded-circle" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 28px; height: 28px;">
                                        <i class="mdi mdi-dots-vertical font-size-16"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li>
                                            <a class="dropdown-item font-size-13" href="javascript:void(0);" 
                                               onclick="openEditDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['category'])) ?>', '<?= sanitize(addslashes($doc['description'] ?? '')) ?>')">
                                                <i class="mdi mdi-pencil-outline me-2 text-info"></i> Edit Keterangan
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item font-size-13 text-danger" href="javascript:void(0);" 
                                               onclick="openDeleteDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['original_name'])) ?>')">
                                                <i class="mdi mdi-trash-can-outline me-2"></i> Hapus Dokumen
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- 2. TABLE / LIST VIEW                                     -->
    <!-- ======================================================== -->
    <div id="docTableView" class="doc-view-section d-none">
        <div class="card border shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="documentsListTable">
                        <thead class="table-light">
                            <tr>
                                <th width="40" class="text-center">#</th>
                                <th width="60" class="text-center">Tipe</th>
                                <th>Judul & Keterangan Dokumen</th>
                                <th>Kategori</th>
                                <th>Nama Asli File</th>
                                <th width="100">Ukuran</th>
                                <th>Pengunggah</th>
                                <th width="110">Tanggal</th>
                                <th width="120" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $idx => $doc): 
                                $typeInfo = getDocTypeInfo($doc['filename'], $doc['file_type']);
                                $downloadUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'];
                                $previewUrl = $baseUrl . '/pages/projects/download_doc.php?id=' . $doc['id'] . '&preview=1';
                                $catMeta = $standardCategories[$doc['category']] ?? ['icon' => 'mdi-tag-outline', 'color' => 'secondary'];
                            ?>
                            <tr class="doc-item-row" 
                                data-id="<?= $doc['id'] ?>"
                                data-title="<?= strtolower(sanitize($doc['title'])) ?>"
                                data-filename="<?= strtolower(sanitize($doc['original_name'])) ?>"
                                data-category="<?= sanitize($doc['category']) ?>"
                                data-description="<?= strtolower(sanitize($doc['description'] ?? '')) ?>"
                                data-uploader="<?= strtolower(sanitize($doc['uploaded_by_name'] ?? '')) ?>">
                                
                                <td class="text-center text-muted font-size-12"><?= $idx + 1 ?></td>
                                
                                <td class="text-center">
                                    <div class="avatar-xs mx-auto d-flex align-items-center justify-content-center rounded" style="background-color: <?= $typeInfo['bg_color'] ?>;">
                                        <i class="mdi <?= $typeInfo['icon'] ?> font-size-20" style="color: <?= $typeInfo['color'] ?>;"></i>
                                    </div>
                                </td>
                                
                                <td>
                                    <div class="fw-bold text-dark font-size-14 doc-title"><?= sanitize($doc['title']) ?></div>
                                    <?php if (!empty($doc['description'])): ?>
                                    <small class="text-muted d-block text-truncate" style="max-width: 320px;" title="<?= sanitize($doc['description']) ?>">
                                        <i class="mdi mdi-information-outline me-1"></i><?= sanitize($doc['description']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <span class="badge bg-soft-<?= $catMeta['color'] ?> text-<?= $catMeta['color'] ?> border border-<?= $catMeta['color'] ?> border-opacity-25 font-size-12">
                                        <i class="mdi <?= $catMeta['icon'] ?> me-1"></i> <?= sanitize($doc['category']) ?>
                                    </span>
                                </td>
                                
                                <td class="font-monospace font-size-12 text-secondary">
                                    <i class="mdi mdi-paperclip me-1"></i><?= sanitize($doc['original_name']) ?>
                                </td>
                                
                                <td class="font-size-12 font-monospace"><?= formatDocBytes($doc['file_size']) ?></td>
                                
                                <td class="font-size-12">
                                    <span class="fw-semibold"><?= sanitize($doc['uploaded_by_name'] ?: 'Sistem') ?></span>
                                </td>
                                
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

                                        <a href="<?= $downloadUrl ?>" class="btn btn-outline-success" title="Download">
                                            <i class="mdi mdi-download"></i>
                                        </a>

                                        <?php if (hasPermission('documentation.upload')): ?>
                                        <button type="button" class="btn btn-outline-info" 
                                                onclick="openEditDocModal(<?= $doc['id'] ?>, '<?= sanitize(addslashes($doc['title'])) ?>', '<?= sanitize(addslashes($doc['category'])) ?>', '<?= sanitize(addslashes($doc['description'] ?? '')) ?>')"
                                                title="Edit">
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
    <div id="noDocMatchAlert" class="alert alert-warning text-center py-4 my-3 d-none">
        <i class="mdi mdi-filter-remove-outline font-size-24 text-warning d-block mb-1"></i>
        <h6 class="fw-bold mb-1">Tidak ada dokumen yang cocok dengan filter / pencarian</h6>
        <p class="text-muted font-size-13 mb-0">Coba ubah kata kunci pencarian atau pilih kategori "Semua".</p>
    </div>

    <?php endif; ?>

</div>

<!-- ======================================================== -->
<!-- MODAL: UPLOAD DOKUMEN                                    -->
<!-- ======================================================== -->
<?php if (hasPermission('documentation.upload')): ?>
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title text-white" id="uploadDocumentModalLabel">
                    <i class="mdi mdi-cloud-upload me-2"></i> Upload Dokumen Proyek
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" action="view.php?id=<?= $projectId ?>" enctype="multipart/form-data" id="uploadDocForm" onsubmit="return validateDocUploadForm()">
                <input type="hidden" name="action" value="upload_project_documents">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                
                <div class="modal-body p-4">
                    
                    <div class="alert alert-info border-info py-2 mb-3">
                        <div class="d-flex align-items-center">
                            <i class="mdi mdi-information-outline font-size-20 me-2"></i>
                            <div class="font-size-13">
                                <strong>Dokumentasi Terbuka:</strong> Anda dapat mengunggah file apapun (PDF, Dokumen Word, Spreadsheet, Presentasi, Gambar Kerja, Foto, CAD, Arsip ZIP, dsb.) agar tim dapat saling mengakses referensi proyek.
                            </div>
                        </div>
                    </div>

                    <!-- File Dropzone Input -->
                    <div class="mb-3">
                        <label class="form-label fw-bold required">Pilih File Dokumen</label>
                        <div class="doc-dropzone border border-2 border-primary border-dashed rounded-3 p-4 text-center bg-light position-relative" id="dropzoneBox" style="cursor: pointer;">
                            <input type="file" class="position-absolute top-0 start-0 w-100 h-100 opacity-0" id="docFileInput" name="documents[]" multiple required onchange="handleFileSelect(this)" style="cursor: pointer;">
                            <div class="dropzone-content">
                                <i class="mdi mdi-cloud-upload-outline font-size-48 text-primary d-block mb-2"></i>
                                <h6 class="fw-bold mb-1">Klik atau seret file ke area ini untuk mengunggah</h6>
                                <p class="text-muted font-size-12 mb-0">Mendukung multi-file upload &bull; Ukuran maksimal hingga 100MB per file</p>
                            </div>
                        </div>
                        
                        <!-- Selected Files Preview List -->
                        <div id="selectedFilesPreview" class="mt-3 d-none">
                            <label class="form-label font-size-12 fw-bold text-muted text-uppercase mb-1">File Terpilih (<span id="selectedFilesCount">0</span>):</label>
                            <div class="list-group font-size-13 shadow-none border" id="selectedFilesList" style="max-height: 180px; overflow-y: auto;">
                            </div>
                        </div>
                    </div>

                    <!-- Document Title (for single file) -->
                    <div class="mb-3" id="docTitleGroup">
                        <label for="docTitle" class="form-label fw-bold">Judul Dokumen / Nama Referensi <span class="text-muted fw-normal font-size-12">(Opsional, otomatis nama file jika kosong)</span></label>
                        <input type="text" class="form-control" id="docTitle" name="title" placeholder="Contoh: Gambar DED Arsitektur Revisi 02">
                    </div>

                    <!-- Category Selector -->
                    <div class="mb-3">
                        <label for="docCategory" class="form-label fw-bold required">Kategori Dokumen</label>
                        <select class="form-select" id="docCategory" name="category" onchange="toggleCustomCategory(this.value)" required>
                            <?php foreach ($standardCategories as $catName => $catMeta): ?>
                            <option value="<?= sanitize($catName) ?>"><?= sanitize($catName) ?></option>
                            <?php endforeach; ?>
                            <option value="custom">-- Tulis Kategori Kustom / Baru --</option>
                        </select>
                    </div>

                    <!-- Custom Category Input (hidden by default) -->
                    <div class="mb-3 d-none" id="customCategoryGroup">
                        <label for="docCustomCategory" class="form-label fw-bold required">Nama Kategori Kustom</label>
                        <input type="text" class="form-control" id="docCustomCategory" name="custom_category" placeholder="Contoh: Dokumen Hasil Uji Laboratorium">
                    </div>

                    <!-- Description / Notes -->
                    <div class="mb-0">
                        <label for="docDescription" class="form-label fw-bold">Catatan & Arah Proyek <span class="text-muted fw-normal font-size-12">(Keterangan agar tim memahami kegunaan dokumen ini)</span></label>
                        <textarea class="form-control" id="docDescription" name="description" rows="3" placeholder="Tuliskan catatan referensi, lingkup kerja terkait, nomor versi, atau instruksi penggunaan file ini..."></textarea>
                    </div>

                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitUploadDoc">
                        <i class="mdi mdi-cloud-upload me-1"></i> Mulai Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- MODAL: EDIT DOKUMEN METADATA                             -->
<!-- ======================================================== -->
<?php if (hasPermission('documentation.upload')): ?>
<div class="modal fade" id="editDocumentModal" tabindex="-1" aria-labelledby="editDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-3">
                <h5 class="modal-title text-white" id="editDocumentModalLabel">
                    <i class="mdi mdi-pencil-box-outline me-2"></i> Edit Keterangan Dokumen
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" action="view.php?id=<?= $projectId ?>" id="editDocForm">
                <input type="hidden" name="action" value="edit_project_document">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="doc_id" id="editDocId">
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="editDocTitle" class="form-label fw-bold required">Judul Dokumen</label>
                        <input type="text" class="form-control" id="editDocTitle" name="title" required>
                    </div>

                    <div class="mb-3">
                        <label for="editDocCategory" class="form-label fw-bold required">Kategori Dokumen</label>
                        <select class="form-select" id="editDocCategory" name="category" onchange="toggleEditCustomCategory(this.value)" required>
                            <?php foreach ($standardCategories as $catName => $catMeta): ?>
                            <option value="<?= sanitize($catName) ?>"><?= sanitize($catName) ?></option>
                            <?php endforeach; ?>
                            <option value="custom">-- Kategori Lainnya (Tulis Manual) --</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="editCustomCategoryGroup">
                        <label for="editDocCustomCategory" class="form-label fw-bold required">Nama Kategori</label>
                        <input type="text" class="form-control" id="editDocCustomCategory" name="custom_category">
                    </div>

                    <div class="mb-0">
                        <label for="editDocDescription" class="form-label fw-bold">Catatan & Keterangan Referensi</label>
                        <textarea class="form-control" id="editDocDescription" name="description" rows="4" placeholder="Keterangan arah proyek / catatan dokumen..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="mdi mdi-content-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- MODAL: HAPUS DOKUMEN                                     -->
<!-- ======================================================== -->
<?php if (hasPermission('documentation.upload')): ?>
<div class="modal fade" id="deleteDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title text-white mb-0"><i class="mdi mdi-trash-can-outline me-1"></i> Hapus Dokumen</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST" action="view.php?id=<?= $projectId ?>" id="deleteDocForm">
                <input type="hidden" name="action" value="delete_project_document">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="doc_id" id="deleteDocId">
                
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
        <div class="modal-content">
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
<!-- CSS STYLES FOR DOCUMENTATION TAB                         -->
<!-- ======================================================== -->
<style>
.doc-card {
    transition: all 0.25s ease-in-out;
    border-radius: 8px;
}
.doc-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12) !important;
}
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
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
.category-pill.active {
    background-color: #556ee6 !important;
    color: #fff !important;
    border-color: #556ee6 !important;
}
.category-pill.active .badge {
    background-color: #fff !important;
    color: #556ee6 !important;
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
.bg-soft-purple {
    background-color: rgba(111, 66, 193, 0.1) !important;
}
.text-purple {
    color: #6f42c1 !important;
}
.border-purple {
    border-color: #6f42c1 !important;
}
</style>

<!-- ======================================================== -->
<!-- JAVASCRIPT FOR DOCUMENTATION TAB                         -->
<!-- ======================================================== -->
<script>
let activeCategoryFilter = 'all';

// Switch View between Grid and Table
function switchDocView(view) {
    if (view === 'grid') {
        $('#docGridView').removeClass('d-none');
        $('#docTableView').addClass('d-none');
        $('#btnViewGrid').addClass('active');
        $('#btnViewTable').removeClass('active');
        localStorage.setItem('pcm_doc_view', 'grid');
    } else {
        $('#docGridView').addClass('d-none');
        $('#docTableView').removeClass('d-none');
        $('#btnViewGrid').removeClass('active');
        $('#btnViewTable').addClass('active');
        localStorage.setItem('pcm_doc_view', 'table');
    }
}

// Category filter
function filterByCategory(category, btn) {
    activeCategoryFilter = category;
    $('.category-pill').removeClass('active');
    $(btn).addClass('active');
    filterDocuments();
}

// Unified filter for search and category
function filterDocuments() {
    const search = ($('#docSearchInput').val() || '').toLowerCase().trim();
    let visibleCount = 0;

    // Filter Grid Cards
    $('.doc-item-card').each(function() {
        const title = $(this).data('title') || '';
        const filename = $(this).data('filename') || '';
        const category = $(this).data('category') || '';
        const description = $(this).data('description') || '';
        const uploader = $(this).data('uploader') || '';

        const matchCategory = (activeCategoryFilter === 'all' || category === activeCategoryFilter);
        const matchSearch = (!search || title.includes(search) || filename.includes(search) || description.includes(search) || uploader.includes(search) || category.toLowerCase().includes(search));

        if (matchCategory && matchSearch) {
            $(this).removeClass('d-none');
            visibleCount++;
        } else {
            $(this).addClass('d-none');
        }
    });

    // Filter Table Rows
    $('.doc-item-row').each(function() {
        const title = $(this).data('title') || '';
        const filename = $(this).data('filename') || '';
        const category = $(this).data('category') || '';
        const description = $(this).data('description') || '';
        const uploader = $(this).data('uploader') || '';

        const matchCategory = (activeCategoryFilter === 'all' || category === activeCategoryFilter);
        const matchSearch = (!search || title.includes(search) || filename.includes(search) || description.includes(search) || uploader.includes(search) || category.toLowerCase().includes(search));

        if (matchCategory && matchSearch) {
            $(this).removeClass('d-none');
        } else {
            $(this).addClass('d-none');
        }
    });

    // Show/hide no match alert
    if (visibleCount === 0 && $('.doc-item-card').length > 0) {
        $('#noDocMatchAlert').removeClass('d-none');
    } else {
        $('#noDocMatchAlert').addClass('d-none');
    }
}

// Drag & Drop / File Select UI handler
function handleFileSelect(input) {
    const files = input.files;
    const count = files ? files.length : 0;
    
    if (count > 0) {
        $('#selectedFilesPreview').removeClass('d-none');
        $('#selectedFilesCount').text(count);
        
        let html = '';
        for (let i = 0; i < count; i++) {
            const f = files[i];
            const sizeFormatted = (f.size < 1024 * 1024) ? (f.size / 1024).toFixed(1) + ' KB' : (f.size / (1024 * 1024)).toFixed(2) + ' MB';
            html += `
                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                    <div class="d-flex align-items-center overflow-hidden me-2">
                        <i class="mdi mdi-file-document-outline font-size-18 text-primary me-2"></i>
                        <span class="text-truncate fw-semibold">${escapeHtml(f.name)}</span>
                    </div>
                    <span class="badge bg-light text-dark font-monospace">${sizeFormatted}</span>
                </div>`;
        }
        $('#selectedFilesList').html(html);

        // If single file and title is empty, prefill title
        if (count === 1 && !$('#docTitle').val()) {
            const baseName = files[0].name.replace(/\.[^/.]+$/, "");
            $('#docTitle').attr('placeholder', baseName);
        }
    } else {
        $('#selectedFilesPreview').addClass('d-none');
        $('#selectedFilesList').empty();
    }
}

// Category custom toggle in upload modal
function toggleCustomCategory(val) {
    if (val === 'custom') {
        $('#customCategoryGroup').removeClass('d-none');
        $('#docCustomCategory').prop('required', true).focus();
    } else {
        $('#customCategoryGroup').addClass('d-none');
        $('#docCustomCategory').prop('required', false);
    }
}

// Category custom toggle in edit modal
function toggleEditCustomCategory(val) {
    if (val === 'custom') {
        $('#editCustomCategoryGroup').removeClass('d-none');
        $('#editDocCustomCategory').prop('required', true).focus();
    } else {
        $('#editCustomCategoryGroup').addClass('d-none');
        $('#editDocCustomCategory').prop('required', false);
    }
}

// Open Edit Document Modal
function openEditDocModal(id, title, category, description) {
    $('#editDocId').val(id);
    $('#editDocTitle').val(title);
    $('#editDocDescription').val(description);
    
    // Check if category in standard options
    const exists = $(`#editDocCategory option[value="${category}"]`).length > 0;
    if (exists) {
        $('#editDocCategory').val(category);
        $('#editCustomCategoryGroup').addClass('d-none');
    } else {
        $('#editDocCategory').val('custom');
        $('#editCustomCategoryGroup').removeClass('d-none');
        $('#editDocCustomCategory').val(category);
    }
    
    new bootstrap.Modal('#editDocumentModal').show();
}

// Open Delete Document Modal
function openDeleteDocModal(id, title, filename) {
    $('#deleteDocId').val(id);
    $('#deleteDocTitle').text(title);
    $('#deleteDocFilename').text(filename);
    new bootstrap.Modal('#deleteDocumentModal').show();
}

// Open Preview Modal (PDF / Image / Viewer)
function openDocPreview(docId, title, filename, previewUrl, isImage, isPdf) {
    $('#previewDocModalTitle').text(title);
    $('#previewDocFilename').text(filename);
    $('#previewDocDownloadBtn').attr('href', '<?= $baseUrl ?>/pages/projects/download_doc.php?id=' + docId);
    $('#previewDocNewTabBtn').attr('href', previewUrl);
    
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

// Form validation on upload
function validateDocUploadForm() {
    const fileInput = document.getElementById('docFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Silakan pilih minimal 1 file dokumen.');
        return false;
    }
    
    $('#btnSubmitUploadDoc').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Mengunggah...');
    return true;
}

// Helper escape
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

// Restore saved view preference
$(document).ready(function() {
    const savedView = localStorage.getItem('pcm_doc_view');
    if (savedView === 'table') {
        switchDocView('table');
    }
});
</script>
