<?php
/**
 * Requests Tab - Project Dashboard
 * Field Team Assignment & Request Management
 */

// Ensure we have project context
if (!isset($project) || !isset($projectId)) {
    die('Invalid access');
}

$needsRedirect = false;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Add Team Members (from checkbox selection)
    if ($_POST['action'] === 'add_team_members' && isAdmin()) {
        $userIds = $_POST['user_ids'] ?? [];
        
        try {
            $pdo = getDB();
            $pdo->beginTransaction();
            
            $addedCount = 0;
            foreach ($userIds as $userId) {
                $userId = intval($userId);
                if ($userId > 0) {
                    // Check if already assigned
                    $existing = dbGetRow(
                        "SELECT id, is_active FROM project_assignments WHERE project_id = ? AND user_id = ?",
                        [$projectId, $userId]
                    );
                    
                    if ($existing) {
                        // Reactivate if inactive
                        if (!$existing['is_active']) {
                            dbExecute(
                                "UPDATE project_assignments SET is_active = 1, assigned_at = NOW() WHERE id = ?",
                                [$existing['id']]
                            );
                            $addedCount++;
                        }
                    } else {
                        // Insert new assignment
                        dbInsert(
                            "INSERT INTO project_assignments (project_id, user_id, assigned_by, is_active) VALUES (?, ?, ?, 1)",
                            [$projectId, $userId, $_SESSION['user_id'] ?? null]
                        );
                        $addedCount++;
                    }
                }
            }
            
            $pdo->commit();
            setFlash('success', $addedCount . ' tim lapangan berhasil ditambahkan.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', 'Gagal menambahkan tim: ' . $e->getMessage());
        }
        
        $needsRedirect = true;
    }
    
    // Remove single team member
    if ($_POST['action'] === 'remove_team_member' && isAdmin()) {
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($userId > 0) {
            try {
                dbExecute(
                    "UPDATE project_assignments SET is_active = 0 WHERE project_id = ? AND user_id = ?",
                    [$projectId, $userId]
                );
                setFlash('success', 'Tim lapangan berhasil dihapus dari penugasan.');
            } catch (Exception $e) {
                setFlash('error', 'Gagal menghapus tim: ' . $e->getMessage());
            }
        }
        
        $needsRedirect = true;
    }
}

// Fetch all field team users
$allFieldTeamUsers = dbGetAll("SELECT id, username, full_name FROM users WHERE role = 'field_team' AND is_active = 1 ORDER BY username ASC");

// Fetch assigned users for this project
$assignedUserIds = [];
$assignedUsers = dbGetAll(
    "SELECT pa.*, u.username, u.full_name 
     FROM project_assignments pa 
     JOIN users u ON pa.user_id = u.id 
     WHERE pa.project_id = ? AND pa.is_active = 1
     ORDER BY u.username",
    [$projectId]
);
foreach ($assignedUsers as $au) {
    $assignedUserIds[] = $au['user_id'];
}

// Fetch requests for this project (enhanced query)
$requests = dbGetAll("
    SELECT r.*, u.full_name as created_by_name, ua.full_name as approved_by_name
    FROM requests r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN users ua ON r.approved_by = ua.id
    WHERE r.project_id = ?
    ORDER BY r.created_at DESC
", [$projectId]);

$canMakeRequest = ($project['status'] === 'on_progress');
?>

<style>
.team-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px 15px;
    margin-bottom: 8px;
    transition: all 0.2s;
}
.team-card.selected {
    border-color: #0d6efd;
    background-color: #e7f1ff;
}
.search-result-item {
    cursor: pointer;
    transition: background-color 0.15s;
}
.search-result-item:hover {
    background-color: #f0f0f0;
}
.search-result-item:last-child {
    border-bottom: none !important;
}
</style>

<div class="row">
    <!-- Left Column: Team Assignment (Admin Only) -->
    <?php if (isAdmin()): ?>
    <div class="col-lg-4 col-md-5">
        <div class="card mb-4">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="mdi mdi-account-group text-primary"></i> Tim Lapangan</h6>
            </div>
            <div class="card-body">
                <?php if (empty($allFieldTeamUsers)): ?>
                <div class="alert alert-info mb-0 py-2">
                    <small><i class="mdi mdi-information"></i> Belum ada user dengan role "field_team". Tambahkan melalui menu User Management.</small>
                </div>
                <?php else: ?>
                
                <?php 
                // Separate available and assigned users
                $availableUsers = [];
                $assignedUsersData = [];
                foreach ($allFieldTeamUsers as $user) {
                    if (in_array($user['id'], $assignedUserIds)) {
                        $assignedUsersData[] = $user;
                    } else {
                        $availableUsers[] = $user;
                    }
                }
                ?>
                
                <!-- Section 1: Available Users (Pilih tim yang ditugaskan) -->
                <div class="mb-4">
                    <p class="text-muted small mb-2">
                        <i class="mdi mdi-account-plus"></i> Pilih tim yang ditugaskan:
                    </p>
                    
                    <!-- Filter Input -->
                    <div class="mb-2">
                        <input type="text" class="form-control form-control-sm" id="filterAvailable" 
                               placeholder="🔍 Filter username..." autocomplete="off"
                               oninput="filterList(this.value, 'availableList')">
                    </div>
                    
                    <!-- Available Users List (max 5 visible, scrollable) -->
                    <div id="availableList" style="max-height: 200px; overflow-y: auto;" class="border rounded p-2 mb-2">
                        <?php if (empty($availableUsers)): ?>
                        <div class="text-muted text-center py-2 empty-msg">
                            <small>Semua tim sudah ditugaskan</small>
                        </div>
                        <?php else: ?>
                        <?php foreach ($availableUsers as $user): ?>
                        <div class="form-check user-item d-flex justify-content-between align-items-center py-1" 
                             data-username="<?= strtolower($user['username']) ?>" 
                             data-fullname="<?= strtolower($user['full_name']) ?>"
                             data-userid="<?= $user['id'] ?>">
                            <div>
                                <input class="form-check-input available-checkbox" type="checkbox" 
                                       value="<?= $user['id'] ?>" 
                                       id="avail_<?= $user['id'] ?>">
                                <label class="form-check-label" for="avail_<?= $user['id'] ?>">
                                    <strong><?= sanitize($user['username']) ?></strong>
                                    <small class="text-muted d-block"><?= sanitize($user['full_name']) ?></small>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="btn btn-primary btn-sm w-100" id="btnTambahTim" onclick="showAddConfirmModal()">
                        <i class="mdi mdi-plus"></i> Tambah
                    </button>
                </div>
                
                <!-- Section 2: Assigned Users (Tim lapangan yang ditugaskan) -->
                <div>
                    <p class="text-muted small mb-2">
                        <i class="mdi mdi-account-check"></i> Tim lapangan yang ditugaskan:
                    </p>
                    
                    <div id="assignedList" style="max-height: 200px; overflow-y: auto;" class="border rounded p-2">
                        <?php if (empty($assignedUsersData)): ?>
                        <div class="text-muted text-center py-2 empty-msg">
                            <small>Belum ada tim yang ditugaskan</small>
                        </div>
                        <?php else: ?>
                        <?php foreach ($assignedUsersData as $user): ?>
                        <div class="assigned-item d-flex justify-content-between align-items-center py-1 border-bottom"
                             data-userid="<?= $user['id'] ?>"
                             data-username="<?= sanitize($user['username']) ?>"
                             data-fullname="<?= sanitize($user['full_name']) ?>">
                            <div>
                                <strong><?= sanitize($user['username']) ?></strong>
                                <small class="text-muted d-block"><?= sanitize($user['full_name']) ?></small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                    onclick="showRemoveConfirmModal(<?= $user['id'] ?>, '<?= sanitize($user['username']) ?>')">
                                <i class="mdi mdi-close"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Right Column: Request List -->
    <div class="<?= isAdmin() ? 'col-lg-8 col-md-7' : 'col-12' ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <h6 class="mb-0"><i class="mdi mdi-clipboard-list text-success"></i> Daftar Pengajuan Dana</h6>
                <?php if ($canMakeRequest): ?>
                <a href="<?= $baseUrl ?>/pages/requests/create.php?project_id=<?= $projectId ?>" class="btn btn-sm btn-success py-0 px-2">
                    <i class="mdi mdi-plus"></i> Buat Pengajuan
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$canMakeRequest && $project['status'] === 'draft'): ?>
                <div class="alert alert-warning mb-3 py-2">
                    <i class="mdi mdi-alert"></i> Pengajuan dana hanya dapat dibuat saat proyek berstatus <strong>ON PROGRESS</strong>.
                </div>
                <?php endif; ?>
                
                <?php if (empty($requests)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="mdi mdi-clipboard-text-outline" style="font-size: 3rem;"></i>
                    <p class="mt-2 mb-0">Belum ada pengajuan dana untuk proyek ini.</p>
                </div>
                <?php else: ?>
                
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>No. Pengajuan</th>
                                <th>Tanggal</th>
                                <th>Deskripsi</th>
                                <th class="text-end">Jumlah</th>
                                <th class="text-center">Status</th>
                                <th>Dibuat</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): 
                                $statusBadges = [
                                    'pending' => '<span class="badge bg-warning">Pending</span>',
                                    'approved' => '<span class="badge bg-success">Approved</span>',
                                    'rejected' => '<span class="badge bg-danger">Rejected</span>'
                                ];
                            ?>
                            <tr>
                                <td><code><?= sanitize($req['request_number'] ?: 'REQ-' . $req['id']) ?></code></td>
                                <td><?= formatDate($req['request_date']) ?></td>
                                <td><?= sanitize($req['description'] ?: '-') ?></td>
                                <td class="text-end"><?= formatRupiah($req['total_amount']) ?></td>
                                <td class="text-center"><?= $statusBadges[$req['status']] ?? $req['status'] ?></td>
                                <td><?= sanitize($req['created_by_name']) ?></td>
                                <td class="text-center">
                                    <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $req['id'] ?>" 
                                       class="btn btn-sm btn-outline-info py-0" title="Lihat Detail">
                                        <i class="mdi mdi-eye"></i>
                                    </a>
                                    <?php if ($req['status'] === 'pending' && isAdmin()): ?>
                                    <a href="<?= $baseUrl ?>/pages/requests/view_request.php?id=<?= $req['id'] ?>" 
                                       class="btn btn-sm btn-outline-success py-0" title="Review & Approve">
                                        <i class="mdi mdi-check-circle"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>



<!-- Modal Konfirmasi Tambah -->
<div class="modal fade" id="addConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="mdi mdi-account-plus text-primary"></i> Konfirmasi</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Tambahkan tim yang dipilih ke proyek ini?</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="view.php?id=<?= $projectId ?>&tab=requests" id="addTeamForm" style="display:inline;">
                    <input type="hidden" name="action" value="add_team_members">
                    <div id="addUserInputs"></div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="mdi mdi-check"></i> Ya, Tambah
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Konfirmasi Hapus -->
<div class="modal fade" id="removeConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="mdi mdi-account-remove text-danger"></i> Konfirmasi</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Hapus <strong id="removeUsername"></strong> dari penugasan proyek ini?</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <form method="POST" action="view.php?id=<?= $projectId ?>&tab=requests" id="removeTeamForm" style="display:inline;">
                    <input type="hidden" name="action" value="remove_team_member">
                    <input type="hidden" name="user_id" id="removeUserId" value="">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="mdi mdi-check"></i> Ya, Hapus
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Filter list by search term
function filterList(searchTerm, listId) {
    searchTerm = searchTerm.toLowerCase().trim();
    var items = document.querySelectorAll('#' + listId + ' .user-item');
    
    items.forEach(function(item) {
        var username = item.getAttribute('data-username') || '';
        var fullname = item.getAttribute('data-fullname') || '';
        
        if (searchTerm === '' || username.indexOf(searchTerm) !== -1 || fullname.indexOf(searchTerm) !== -1) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// Show add confirmation modal
function showAddConfirmModal() {
    var checked = document.querySelectorAll('.available-checkbox:checked');
    
    if (checked.length === 0) {
        alert('Pilih minimal satu tim untuk ditambahkan');
        return;
    }
    
    // Clear previous inputs
    var container = document.getElementById('addUserInputs');
    container.innerHTML = '';
    
    // Add hidden inputs for each selected user
    checked.forEach(function(cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'user_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    
    // Show modal
    var modal = new bootstrap.Modal(document.getElementById('addConfirmModal'));
    modal.show();
}

// Show remove confirmation modal
function showRemoveConfirmModal(userId, username) {
    document.getElementById('removeUserId').value = userId;
    document.getElementById('removeUsername').textContent = username;
    
    var modal = new bootstrap.Modal(document.getElementById('removeConfirmModal'));
    modal.show();
}
</script>

<?php if ($needsRedirect): ?>
<script>
    window.location.href = 'view.php?id=<?= $projectId ?>&tab=requests';
</script>
<?php endif; ?>
