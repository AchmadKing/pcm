<?php
/**
 * Detail Tab - Project Dashboard
 */
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

<?php if (isAdmin() && $project['status'] === 'draft'): ?>
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
