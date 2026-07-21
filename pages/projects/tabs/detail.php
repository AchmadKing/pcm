<?php
/**
 * Detail Tab - Project Dashboard
 */

$projectImages = dbGetAll("
    SELECT pi.*, u.full_name as uploaded_by_name 
    FROM project_images pi 
    LEFT JOIN users u ON pi.uploaded_by = u.id 
    WHERE pi.project_id = ? 
    ORDER BY pi.created_at DESC
", [$projectId]);
?>


<div class="row">
    <div class="col-lg-6">
        <h5 class="font-size-14 mb-3"><i class="mdi mdi-information-outline"></i> Informasi Dasar</h5>
        <table class="table table-borderless mb-0">
            <tr>
                <td class="text-muted" width="40%">Nama Proyek</td>
                <td><strong><?= sanitize($project['name']) ?></strong></td>
            </tr>
            <tr>
                <td class="text-muted">Kode Proyek</td>
                <td><code><?= sanitize($project['project_code'] ?: '-') ?></code></td>
            </tr>
            <tr>
                <td class="text-muted">Wilayah</td>
                <td><?= sanitize($project['region_name'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Nama Kegiatan</td>
                <td><?= sanitize($project['activity_name'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Pekerjaan</td>
                <td><?= sanitize($project['work_description'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Sumber Dana</td>
                <td><?= sanitize($project['funding_source'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Tahun Anggaran</td>
                <td><?= $project['budget_year'] ?: '-' ?></td>
            </tr>
            <tr>
                <td class="text-muted">Overhead</td>
                <td><?= $project['overhead_percentage'] ?>%</td>
            </tr>
            <tr>
                <td class="text-muted">PPN</td>
                <td><?= $project['ppn_percentage'] ?>%</td>
            </tr>
        </table>
    </div>
    
    <div class="col-lg-6">
        <h5 class="font-size-14 mb-3"><i class="mdi mdi-file-document-outline"></i> Informasi Kontrak</h5>
        <table class="table table-borderless mb-0">
            <tr>
                <td class="text-muted" width="40%">No. Kontrak</td>
                <td><?= sanitize($project['contract_number'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Tanggal Kontrak</td>
                <td><?= $project['contract_date'] ? formatDate($project['contract_date']) : '-' ?></td>
            </tr>
            <tr>
                <td class="text-muted">Penyedia Jasa</td>
                <td><?= sanitize($project['service_provider'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Konsultan Pengawas</td>
                <td><?= sanitize($project['supervisor_consultant'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Durasi</td>
                <td><?= $project['duration_days'] ?> Hari</td>
            </tr>
            <tr>
                <td class="text-muted">Tanggal Mulai</td>
                <td><?= $project['start_date'] ? formatDate($project['start_date']) : '-' ?></td>
            </tr>
            <tr>
                <td class="text-muted">Dibuat Oleh</td>
                <td><?= sanitize($project['created_by_name'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="text-muted">Dibuat Pada</td>
                <td><?= formatDate($project['created_at']) ?></td>
            </tr>
        </table>
    </div>
</div>

<?php if ($project['description']): ?>
<hr>
<h5 class="font-size-14 mb-3"><i class="mdi mdi-note-text"></i> Keterangan</h5>
<p class="text-muted"><?= nl2br(sanitize($project['description'])) ?></p>
<?php endif; ?>

<?php if (hasPermission('projects.edit') && $project['status'] === 'draft'): ?>
<hr>
<div class="d-flex gap-2 flex-wrap">
    <a href="edit.php?id=<?= $projectId ?>" class="btn btn-outline-primary">
        <i class="mdi mdi-pencil"></i> Edit Proyek
    </a>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#startProjectModal">
        <i class="mdi mdi-play-circle"></i> Mulai Proyek
    </button>
</div>

<!-- Modal Konfirmasi Mulai Proyek -->
<div class="modal fade" id="startProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="mdi mdi-play-circle text-success"></i> Mulai Proyek</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-3">
                    <i class="mdi mdi-alert"></i>
                    <strong>Apakah Anda yakin?</strong> Setelah proyek dimulai:
                    <ul class="mb-0 mt-2">
                        <li>Data <strong>RAB</strong> akan dikunci dan tidak bisa diedit</li>
                        <li>Data <strong>RAP</strong> akan dikunci dan tidak bisa diedit</li>
                        <li>Data <strong>Master Data</strong> (Item & Harga) akan dikunci</li>
                        <li>Data <strong>AHSP</strong> akan dikunci dan tidak bisa diedit</li>
                        <li>Pengajuan dana dapat dilakukan oleh tim lapangan</li>
                        <li>Pencatatan progress mingguan dapat dimulai</li>
                    </ul>
                </div>
                <div class="alert alert-info mb-0">
                    <i class="mdi mdi-information"></i>
                    <strong>Catatan:</strong> Jika terjadi kesalahan fatal, Admin dapat menggunakan tombol "Kembali ke Draft" untuk membuka kunci kembali.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="view.php?id=<?= $projectId ?>" class="d-inline">
                    <input type="hidden" name="action" value="start_project">
                    <button type="submit" class="btn btn-success">
                        <i class="mdi mdi-play-circle"></i> Ya, Mulai Proyek
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- =====================================================
     PROYEK / LAPANGAN FOTO GALLERY SECTION
     ===================================================== -->
<hr class="my-4">
<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="font-size-16 mb-1 text-primary"><i class="mdi mdi-image-multiple me-2"></i> Foto Dokumentasi Proyek / Lapangan</h5>
                <p class="text-muted font-size-13 mb-0">Galeri foto visual kondisi di lapangan atau progres proyek.</p>
            </div>
            <?php if (hasPermission('projects.edit')): ?>
            <button type="button" class="btn btn-primary d-flex align-items-center shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                <i class="mdi mdi-cloud-upload font-size-18 me-1"></i> Upload Foto
            </button>
            <?php endif; ?>
        </div>

        <?php if (empty($projectImages)): ?>
        <div class="card border border-dashed text-center py-5">
            <div class="card-body">
                <div class="avatar-md mx-auto mb-3">
                    <div class="avatar-title bg-light text-primary rounded-circle fs-3 shadow-sm">
                        <i class="mdi mdi-image-off-outline"></i>
                    </div>
                </div>
                <h5 class="font-size-15 text-dark">Belum Ada Foto Dokumentasi</h5>
                <p class="text-muted mb-0">Belum ada foto lokasi proyek atau lapangan yang diunggah untuk proyek ini.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4" id="gallery_grid">
            <?php foreach ($projectImages as $idx => $img): ?>
            <div class="col">
                <div class="card h-100 shadow-sm border gallery-card overflow-hidden">
                    <div class="position-relative gallery-img-container" style="background-color: #f3f3f3;">
                        <img src="<?= $baseUrl ?>/uploads/project_images/<?= sanitize($img['filename']) ?>" 
                             alt="<?= sanitize($img['original_name']) ?>" 
                             class="img-fluid w-100 gallery-img" 
                             style="height: 180px; object-fit: cover; cursor: pointer;"
                             onclick="showLightbox(<?= $idx ?>)">
                        
                        <?php if (hasPermission('projects.edit')): ?>
                        <div class="position-absolute top-0 end-0 p-2 gallery-actions">
                            <button type="button" class="btn btn-light btn-sm text-primary rounded-circle shadow-sm me-1 border-0 shadow-none" 
                                    onclick="openEditDescriptionModal(<?= $img['id'] ?>, '<?= sanitize(addslashes($img['description'] ?? '')) ?>')"
                                    title="Edit Keterangan" style="width: 32px; height: 32px; padding: 0;">
                                <i class="mdi mdi-pencil-outline font-size-15"></i>
                            </button>
                            <button type="button" class="btn btn-light btn-sm text-danger rounded-circle shadow-sm border-0 shadow-none" 
                                    onclick="openDeletePhotoModal(<?= $img['id'] ?>)"
                                    title="Hapus Foto" style="width: 32px; height: 32px; padding: 0;">
                                <i class="mdi mdi-trash-can-outline font-size-15"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-3 d-flex flex-column justify-content-between">
                        <div>
                            <p class="text-dark font-size-13 mb-0 text-truncate-2" title="<?= sanitize($img['description'] ?? 'Tidak ada keterangan') ?>">
                                <?= $img['description'] ? sanitize($img['description']) : '<em class="text-muted">Tidak ada keterangan</em>' ?>
                            </p>
                        </div>
                        <div class="mt-3 pt-2 border-top">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted text-truncate" style="max-width: 65%;" title="Diunggah oleh <?= sanitize($img['uploaded_by_name'] ?: 'System') ?>">
                                    <i class="mdi mdi-account-circle-outline me-1 text-primary"></i><strong><?= sanitize($img['uploaded_by_name'] ?: 'System') ?></strong>
                                </small>
                                <small class="text-muted font-size-11">
                                    <?= date('d/m/Y', strtotime($img['created_at'])) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- =====================================================
     GALLERY CSS / STYLING
     ===================================================== -->
<style>
.gallery-card {
    transition: transform 0.25s ease-in-out, box-shadow 0.25s ease-in-out;
}
.gallery-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.12) !important;
}
.gallery-img-container {
    overflow: hidden;
    position: relative;
}
.gallery-actions {
    z-index: 10;
    transition: opacity 0.2s ease-in-out;
    opacity: 0;
}
.gallery-img-container:hover .gallery-actions {
    opacity: 1 !important;
}
.gallery-img {
    transition: transform 0.4s ease;
}
.gallery-img:hover {
    transform: scale(1.06);
}
.text-truncate-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;  
    overflow: hidden;
    line-height: 1.5;
    height: 3rem; /* fallback */
}
.max-h-75vh {
    max-height: 75vh;
}
/* Focus border-less button stylings */
.btn-close-white:focus {
    box-shadow: none !important;
}
</style>

<!-- =====================================================
     GALLERY MODALS
     ===================================================== -->

<?php if (hasPermission('projects.edit')): ?>
<!-- Modal Upload Foto -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1" aria-labelledby="uploadPhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadPhotoModalLabel"><i class="mdi mdi-upload text-primary me-2"></i> Upload Foto Proyek</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view.php?id=<?= $projectId ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_project_images">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="mdi mdi-information-outline me-1"></i>
                        Pilih satu atau lebih file gambar (JPG, JPEG, PNG, WEBP). Maksimal ukuran per file adalah 5MB.
                    </div>
                    
                    <div class="mb-3">
                        <label for="image_input" class="form-label required">Pilih File Gambar</label>
                        <input class="form-control" type="file" id="image_input" name="images[]" multiple accept="image/jpeg,image/png,image/jpg,image/webp" required>
                    </div>
                    
                    <div id="selected_files_preview" class="d-none">
                        <h6 class="mb-3 font-size-14 text-dark border-bottom pb-2">Keterangan untuk masing-masing gambar:</h6>
                        <div id="preview_container" class="row g-3">
                            <!-- Dynamically populated via JS -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-cloud-upload me-1"></i> Mulai Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Keterangan -->
<div class="modal fade" id="editDescriptionModal" tabindex="-1" aria-labelledby="editDescriptionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editDescriptionModalLabel"><i class="mdi mdi-pencil text-primary me-2"></i> Edit Keterangan Foto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view.php?id=<?= $projectId ?>">
                <input type="hidden" name="action" value="edit_project_image_description">
                <input type="hidden" name="image_id" id="edit_image_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_image_description" class="form-label">Keterangan Foto</label>
                        <textarea class="form-control" id="edit_image_description" name="description" rows="3" placeholder="Masukkan keterangan foto lokasi proyek..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Hapus Foto -->
<div class="modal fade" id="deletePhotoModal" tabindex="-1" aria-labelledby="deletePhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deletePhotoModalLabel"><i class="mdi mdi-alert text-danger me-2"></i> Hapus Foto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="view.php?id=<?= $projectId ?>">
                <input type="hidden" name="action" value="delete_project_image">
                <input type="hidden" name="image_id" id="delete_image_id">
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus foto ini? File foto di server akan dihapus secara permanen dan tidak dapat dikembalikan.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Hapus Permanen</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Lightbox Preview -->
<div class="modal fade" id="lightboxModal" tabindex="-1" aria-hidden="true" style="background-color: rgba(15, 15, 15, 0.92); z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 bg-transparent text-white">
            <div class="modal-header border-0 p-0 mb-3 justify-content-end">
                <button type="button" class="btn-close btn-close-white font-size-18" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1) grayscale(100%) brightness(200%);"></button>
            </div>
            <div class="modal-body text-center p-0">
                <div class="position-relative d-inline-block">
                    <img id="lightbox_img" src="" class="img-fluid rounded shadow-lg max-h-75vh" style="object-fit: contain; max-width: 100%;" />
                    
                    <!-- Prev button -->
                    <button type="button" id="prev_photo_btn" class="btn btn-dark rounded-circle position-absolute top-50 start-0 translate-middle-y ms-3 bg-opacity-75 border-0 d-flex align-items-center justify-content-center" onclick="navigatePhoto(-1)" style="width: 45px; height: 45px; font-size: 24px; z-index: 100; transition: background-color 0.2s; padding: 0;">
                        <i class="mdi mdi-chevron-left"></i>
                    </button>
                    
                    <!-- Next button -->
                    <button type="button" id="next_photo_btn" class="btn btn-dark rounded-circle position-absolute top-50 end-0 translate-middle-y me-3 bg-opacity-75 border-0 d-flex align-items-center justify-content-center" onclick="navigatePhoto(1)" style="width: 45px; height: 45px; font-size: 24px; z-index: 100; transition: background-color 0.2s; padding: 0;">
                        <i class="mdi mdi-chevron-right"></i>
                    </button>
                </div>
                
                <div class="mt-4 p-3 rounded bg-dark bg-opacity-75 text-start mx-auto" style="max-width: 800px; border-left: 4px solid #556ee6;">
                    <p id="lightbox_desc" class="fs-5 mb-2 text-white"></p>
                    <div class="d-flex justify-content-between border-top border-secondary pt-2 mt-2">
                        <small class="text-white-50">
                            <i class="mdi mdi-account-circle-outline me-1"></i> Diunggah oleh: <span id="lightbox_uploader" class="fw-semibold text-white"></span>
                        </small>
                        <small class="text-white-50">
                            <i class="mdi mdi-calendar-clock me-1"></i> Tanggal: <span id="lightbox_date" class="text-white"></span>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     GALLERY JAVASCRIPT
     ===================================================== -->
<script>
let currentPhotoIndex = 0;
const projectPhotos = <?php echo json_encode(array_map(function($img) use ($baseUrl) {
    return [
        'src' => $baseUrl . '/uploads/project_images/' . $img['filename'],
        'desc' => $img['description'] ?: '',
        'uploader' => $img['uploaded_by_name'] ?: 'System',
        'date' => formatDate($img['created_at'])
    ];
}, $projectImages)); ?>;

function showLightbox(index) {
    currentPhotoIndex = index;
    updateLightbox();
    const lightboxModal = new bootstrap.Modal(document.getElementById('lightboxModal'));
    lightboxModal.show();
}

function updateLightbox() {
    const photo = projectPhotos[currentPhotoIndex];
    if (!photo) return;
    
    document.getElementById('lightbox_img').src = photo.src;
    document.getElementById('lightbox_desc').innerHTML = photo.desc ? photo.desc.replace(/\n/g, '<br>') : '<em class="text-muted">Tidak ada keterangan</em>';
    document.getElementById('lightbox_uploader').innerText = photo.uploader;
    document.getElementById('lightbox_date').innerText = photo.date;
    
    // Toggle navigation button visibility
    document.getElementById('prev_photo_btn').style.display = projectPhotos.length > 1 ? 'flex' : 'none';
    document.getElementById('next_photo_btn').style.display = projectPhotos.length > 1 ? 'flex' : 'none';
}

function navigatePhoto(direction) {
    currentPhotoIndex += direction;
    if (currentPhotoIndex < 0) {
        currentPhotoIndex = projectPhotos.length - 1;
    } else if (currentPhotoIndex >= projectPhotos.length) {
        currentPhotoIndex = 0;
    }
    updateLightbox();
}

// Keyboard arrow keys navigation support for lightbox
document.addEventListener('keydown', function(event) {
    const lightboxElement = document.getElementById('lightboxModal');
    if (lightboxElement && lightboxElement.classList.contains('show') && projectPhotos.length > 1) {
        if (event.key === 'ArrowLeft') {
            navigatePhoto(-1);
        } else if (event.key === 'ArrowRight') {
            navigatePhoto(1);
        }
    }
});

<?php if (hasPermission('projects.edit')): ?>
// Selected files list preview with description fields inside the Upload Modal
document.getElementById('image_input').addEventListener('change', function(event) {
    const previewContainer = document.getElementById('preview_container');
    const selectedFilesPreview = document.getElementById('selected_files_preview');
    previewContainer.innerHTML = '';
    
    const files = event.target.files;
    if (files.length > 0) {
        selectedFilesPreview.classList.remove('d-none');
        Array.from(files).forEach((file, index) => {
            const reader = new FileReader();
            
            // Create a preview column
            const col = document.createElement('div');
            col.className = 'col-12 col-md-6';
            
            reader.onload = function(e) {
                col.innerHTML = `
                    <div class="card h-100 border p-2 shadow-none mb-0 bg-light">
                        <div class="row g-2 align-items-center">
                            <div class="col-4">
                                <img src="${e.target.result}" class="img-fluid rounded border shadow-sm" style="height: 75px; width: 100%; object-fit: cover;" />
                            </div>
                            <div class="col-8">
                                <label class="form-label font-size-12 text-truncate d-block mb-1 fw-bold" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</label>
                                <input type="text" name="descriptions[${index}]" class="form-control form-control-sm" placeholder="Keterangan gambar..." />
                            </div>
                        </div>
                    </div>
                `;
            }
            reader.readAsDataURL(file);
            previewContainer.appendChild(col);
        });
    } else {
        selectedFilesPreview.classList.add('d-none');
    }
});

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function openEditDescriptionModal(id, desc) {
    document.getElementById('edit_image_id').value = id;
    document.getElementById('edit_image_description').value = desc;
    
    const editModal = new bootstrap.Modal(document.getElementById('editDescriptionModal'));
    editModal.show();
}

function openDeletePhotoModal(id) {
    document.getElementById('delete_image_id').value = id;
    
    const deleteModal = new bootstrap.Modal(document.getElementById('deletePhotoModal'));
    deleteModal.show();
}
<?php endif; ?>
</script>
