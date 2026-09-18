<?php
/**
 * Modal: Kalkulator Tenaga Kerja
 * PCC - Project Cost Control System
 * 
 * Reusable modal component for calculating labor requirements / project duration
 * based on AHSP coefficients and RAP volumes.
 */

// Determine current project context if available
$calcDefaultProjectId = $projectId ?? ($project['id'] ?? ($projectFilter ?? ''));
if (empty($calcDefaultProjectId) && isset($_GET['project_id'])) {
    $calcDefaultProjectId = intval($_GET['project_id']);
}
?>

<!-- Modal Kalkulator Tenaga Kerja -->
<div class="modal fade" id="modalLaborCalculator" tabindex="-1" aria-labelledby="modalLaborCalculatorLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
            <!-- Modal Header -->
            <div class="modal-header bg-gradient bg-primary text-white py-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white text-primary p-2 me-3 d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px;">
                        <i class="mdi mdi-calculator font-size-22"></i>
                    </div>
                    <div>
                        <h5 class="modal-title text-white mb-0 fw-bold" id="modalLaborCalculatorLabel">Kalkulator Tenaga Kerja</h5>
                        <small class="text-white-50">Alat bantu estimasi kebutuhan tenaga kerja & durasi berbasis AHSP & RAP</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4 bg-light">
                
                <!-- Project Selection (Shown only when no fixed project is selected) -->
                <div class="mb-3" id="calcProjectContainer" style="<?= !empty($calcDefaultProjectId) ? 'display: none;' : '' ?>">
                    <label class="form-label fw-bold text-dark font-size-13">
                        <i class="mdi mdi-folder-outline text-primary me-1"></i> Proyek <span class="text-danger">*</span>
                    </label>
                    <select class="form-select border-primary-subtle" id="calcProjectSelect">
                        <option value="">-- Pilih Proyek --</option>
                    </select>
                </div>

                <!-- Alert Container for dynamic errors/warnings -->
                <div id="calcAlertContainer"></div>

                <!-- Card Step 1 & 2: AHSP & Item -->
                <div class="card border-0 shadow-sm rounded-3 mb-3">
                    <div class="card-body p-3">
                        <div class="row g-3">
                            <!-- Step 1: AHSP Selection -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark font-size-13 mb-1">
                                    <span class="badge bg-primary rounded-pill me-1">1</span> AHSP / Pekerjaan <span class="text-danger">*</span>
                                </label>
                                <select class="form-select shadow-none" id="calcAhspSelect" disabled>
                                    <option value="">-- Pilih AHSP --</option>
                                </select>
                                <small class="text-muted font-size-11 mt-1 d-block">Data diambil dari RAP & AHSP proyek.</small>
                            </div>

                            <!-- Step 2: Item Selection -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark font-size-13 mb-1">
                                    <span class="badge bg-primary rounded-pill me-1">2</span> Item Tenaga Kerja <span class="text-danger">*</span>
                                </label>
                                <select class="form-select shadow-none" id="calcItemSelect" disabled>
                                    <option value="">-- Pilih Item --</option>
                                </select>
                                <small class="text-muted font-size-11 mt-1 d-block">Hanya menampilkan tenaga kerja pada AHSP terpilih.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Auto-populated Read-Only Info -->
                <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
                    <div class="card-header bg-transparent py-2 border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold font-size-12 text-secondary text-uppercase tracking-wider">
                            <i class="mdi mdi-information-outline text-info me-1"></i> Informasi Pekerjaan (Read-Only)
                        </span>
                        <span class="badge bg-light text-muted font-size-11 border">Otomatis dari Sistem</span>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="p-2 rounded bg-light border">
                                    <div class="text-muted font-size-11 mb-1">Koefisien AHSP</div>
                                    <div class="d-flex align-items-center">
                                        <i class="mdi mdi-percent-outline text-primary me-2 font-size-18"></i>
                                        <div class="fw-bold font-size-16 text-dark" id="calcDisplayCoefficient">-</div>
                                    </div>
                                    <small class="text-muted font-size-10">Berasal dari Detail AHSP terpilih</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-2 rounded bg-light border">
                                    <div class="text-muted font-size-11 mb-1">Volume RAP</div>
                                    <div class="d-flex align-items-center">
                                        <i class="mdi mdi-cube-outline text-success me-2 font-size-18"></i>
                                        <div class="fw-bold font-size-16 text-dark" id="calcDisplayVolume">-</div>
                                    </div>
                                    <small class="text-muted font-size-10">Berasal dari data RAP proyek</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Calculation Mode & Input -->
                <div class="card border-0 shadow-sm rounded-3 mb-3">
                    <div class="card-header bg-transparent py-2 border-bottom">
                        <span class="fw-bold font-size-12 text-secondary text-uppercase tracking-wider">
                            <span class="badge bg-primary rounded-pill me-1">3</span> Mode Perhitungan & Input
                        </span>
                    </div>
                    <div class="card-body p-3">
                        <!-- Mode Selector Pills -->
                        <div class="d-flex p-1 bg-light rounded-pill mb-3 border" role="tablist">
                            <button type="button" class="btn btn-sm rounded-pill flex-fill fw-bold calc-mode-btn active shadow-sm" id="btnModeWorker" data-mode="worker">
                                <i class="mdi mdi-account-hard-hat me-1"></i> Hitung Pekerja
                            </button>
                            <button type="button" class="btn btn-sm rounded-pill flex-fill fw-bold calc-mode-btn text-muted" id="btnModeDays" data-mode="days">
                                <i class="mdi mdi-calendar-clock me-1"></i> Hitung Hari
                            </button>
                        </div>

                        <!-- Input Container -->
                        <div class="row g-3 align-items-end">
                            <!-- Mode 1 Input: Jumlah Hari -->
                            <div class="col-md-8" id="calcInputDaysContainer">
                                <label class="form-label fw-bold text-dark font-size-13 mb-1" for="calcInputDays">
                                    Jumlah Hari Kerja <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="mdi mdi-calendar-range text-primary"></i></span>
                                    <input type="number" class="form-control" id="calcInputDays" placeholder="Masukkan target jumlah hari (contoh: 6)" min="0.01" step="any" disabled>
                                    <span class="input-group-text bg-light text-muted">Hari</span>
                                </div>
                            </div>

                            <!-- Mode 2 Input: Jumlah Pekerja -->
                            <div class="col-md-8 d-none" id="calcInputWorkersContainer">
                                <label class="form-label fw-bold text-dark font-size-13 mb-1" for="calcInputWorkers">
                                    Jumlah Pekerja <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="mdi mdi-account-group text-primary"></i></span>
                                    <input type="number" class="form-control" id="calcInputWorkers" placeholder="Masukkan jumlah pekerja (contoh: 5)" min="1" step="1" disabled>
                                    <span class="input-group-text bg-light text-muted">Orang</span>
                                </div>
                            </div>

                            <!-- Calculate Button -->
                            <div class="col-md-4">
                                <button type="button" class="btn btn-primary w-100 fw-bold py-2 shadow-sm" id="btnRunCalculate" disabled>
                                    <i class="mdi mdi-calculator-variant me-1"></i> HITUNG
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Calculation Results -->
                <div class="card border-0 shadow-sm rounded-3 d-none overflow-hidden" id="calcResultContainer">
                    <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold font-size-12 text-uppercase">
                            <i class="mdi mdi-check-circle-outline text-success me-1"></i> Hasil Perhitungan
                        </span>
                        <span class="badge bg-success font-size-11" id="calcResultModeBadge">Hitung Pekerja</span>
                    </div>
                    <div class="card-body p-3 bg-white">
                        <div class="row g-3 text-center">
                            <!-- Metric 1: Total OH -->
                            <div class="col-4">
                                <div class="p-3 rounded-3 bg-light border h-100">
                                    <div class="text-muted font-size-12 mb-1">Total OH</div>
                                    <div class="font-size-20 fw-bold text-dark" id="calcResTotalOH">0,00</div>
                                    <small class="text-muted font-size-11">Volume × Koefisien</small>
                                </div>
                            </div>

                            <!-- Metric 2: Decimal Result -->
                            <div class="col-4">
                                <div class="p-3 rounded-3 bg-light border h-100">
                                    <div class="text-muted font-size-12 mb-1" id="calcResMetric2Label">Kebutuhan Tenaga</div>
                                    <div class="font-size-20 fw-bold text-primary" id="calcResMetric2Value">0,00</div>
                                    <small class="text-muted font-size-11" id="calcResMetric2Sub">Nilai Desimal</small>
                                </div>
                            </div>

                            <!-- Metric 3: Ceil (Actual) Result -->
                            <div class="col-4">
                                <div class="p-3 rounded-3 border h-100" style="background: linear-gradient(135deg, #e7f5ea 0%, #d4edda 100%); border-color: #28a745 !important;">
                                    <div class="text-success-emphasis fw-bold font-size-12 mb-1" id="calcResMetric3Label">Jumlah Aktual</div>
                                    <div class="font-size-24 fw-bolder text-success" id="calcResMetric3Value">0</div>
                                    <small class="text-success-emphasis font-size-11 fw-semibold">Pembulatan ke Atas</small>
                                </div>
                            </div>
                        </div>

                        <!-- Formula explanation footer -->
                        <div class="mt-3 p-2 bg-light rounded text-muted font-size-11 border d-flex align-items-center">
                            <i class="mdi mdi-lightbulb-on-outline text-warning font-size-16 me-2"></i>
                            <span id="calcFormulaExplain">Rumus: Kebutuhan Pekerja = (Volume × Koefisien) / Hari</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-footer bg-light py-2 px-4 border-top d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnResetCalculator">
                    <i class="mdi mdi-refresh me-1"></i> RESET
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    TUTUP
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.calc-mode-btn {
    transition: all 0.2s ease-in-out;
    border: none !important;
}
.calc-mode-btn.active {
    background-color: #0d6efd !important;
    color: #ffffff !important;
}
.calc-mode-btn:not(.active):hover {
    background-color: #dee2e6;
}
</style>

<script>
/**
 * Kalkulator Tenaga Kerja - Client Logic
 */
(function() {
    // Current State
    var calcState = {
        projectId: <?= !empty($calcDefaultProjectId) ? intval($calcDefaultProjectId) : 'null' ?>,
        mode: 'worker', // 'worker' (Hitung Pekerja) or 'days' (Hitung Hari)
        ahsps: [],
        currentAhsp: null,
        currentItems: [],
        selectedItem: null,
        volume: 0,
        volumeUnit: '',
        coefficient: 0
    };

    var endpointUrl = '<?= getBaseUrl() ?>/pages/requests/ajax_calculator.php';

    // DOM Elements
    var modalEl = document.getElementById('modalLaborCalculator');
    var projectContainer = document.getElementById('calcProjectContainer');
    var projectSelect = document.getElementById('calcProjectSelect');
    var ahspSelect = document.getElementById('calcAhspSelect');
    var itemSelect = document.getElementById('calcItemSelect');
    var displayCoeff = document.getElementById('calcDisplayCoefficient');
    var displayVol = document.getElementById('calcDisplayVolume');
    var alertContainer = document.getElementById('calcAlertContainer');
    
    var btnModeWorker = document.getElementById('btnModeWorker');
    var btnModeDays = document.getElementById('btnModeDays');
    var inputDaysContainer = document.getElementById('calcInputDaysContainer');
    var inputWorkersContainer = document.getElementById('calcInputWorkersContainer');
    var inputDays = document.getElementById('calcInputDays');
    var inputWorkers = document.getElementById('calcInputWorkers');
    var btnRunCalculate = document.getElementById('btnRunCalculate');
    
    var resultContainer = document.getElementById('calcResultContainer');
    var resultModeBadge = document.getElementById('calcResultModeBadge');
    var resTotalOH = document.getElementById('calcResTotalOH');
    var resMetric2Label = document.getElementById('calcResMetric2Label');
    var resMetric2Value = document.getElementById('calcResMetric2Value');
    var resMetric2Sub = document.getElementById('calcResMetric2Sub');
    var resMetric3Label = document.getElementById('calcResMetric3Label');
    var resMetric3Value = document.getElementById('calcResMetric3Value');
    var formulaExplain = document.getElementById('calcFormulaExplain');
    var btnReset = document.getElementById('btnResetCalculator');

    // Helper: Number formatter
    function formatDec(num, dec) {
        if (dec === undefined) dec = 2;
        if (num === null || num === undefined || isNaN(num)) return '-';
        return Number(num).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    // Helper: Alert Display
    function showAlert(message, type) {
        if (!type) type = 'warning';
        alertContainer.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-2 font-size-13" role="alert">' +
            '<i class="mdi mdi-alert-circle-outline me-1"></i> ' + message +
            '<button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '</div>';
    }

    function clearAlert() {
        alertContainer.innerHTML = '';
    }

    // Reset Calculator UI
    function resetCalculator(fullReset) {
        if (fullReset) {
            calcState.selectedItem = null;
            calcState.currentAhsp = null;
            calcState.volume = 0;
            calcState.volumeUnit = '';
            calcState.coefficient = 0;
            ahspSelect.value = '';
            itemSelect.innerHTML = '<option value="">-- Pilih Item --</option>';
            itemSelect.disabled = true;
        } else {
            calcState.selectedItem = null;
            calcState.coefficient = 0;
            itemSelect.value = '';
        }

        displayCoeff.innerText = '-';
        displayVol.innerText = '-';
        inputDays.value = '';
        inputWorkers.value = '';
        inputDays.disabled = true;
        inputWorkers.disabled = true;
        btnRunCalculate.disabled = true;
        resultContainer.classList.add('d-none');
        clearAlert();
    }

    // Load Projects into Project Dropdown (if needed)
    function loadProjects() {
        fetch(endpointUrl + '?action=get_projects')
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.success && res.data) {
                    var html = '<option value="">-- Pilih Proyek --</option>';
                    res.data.forEach(function(p) {
                        var selected = (calcState.projectId == p.id) ? 'selected' : '';
                        html += '<option value="' + p.id + '" ' + selected + '>' + p.name + '</option>';
                    });
                    projectSelect.innerHTML = html;
                    if (calcState.projectId) {
                        loadAhsps(calcState.projectId);
                    }
                }
            })
            .catch(function(err) {
                console.error('Error loading projects:', err);
            });
    }

    // Load AHSPs for a Project
    function loadAhsps(projectId) {
        if (!projectId) {
            ahspSelect.innerHTML = '<option value="">-- Pilih AHSP --</option>';
            ahspSelect.disabled = true;
            return;
        }

        ahspSelect.innerHTML = '<option value="">Memuat AHSP...</option>';
        ahspSelect.disabled = true;

        fetch(endpointUrl + '?action=get_ahsps&project_id=' + projectId)
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.success && res.data && res.data.length > 0) {
                    calcState.ahsps = res.data;
                    var html = '<option value="">-- Pilih AHSP --</option>';
                    res.data.forEach(function(item, idx) {
                        html += '<option value="' + idx + '">' + item.display_label + '</option>';
                    });
                    ahspSelect.innerHTML = html;
                    ahspSelect.disabled = false;
                } else {
                    calcState.ahsps = [];
                    ahspSelect.innerHTML = '<option value="">(Tidak ada data AHSP pada proyek ini)</option>';
                    ahspSelect.disabled = true;
                    showAlert('Tidak ada data AHSP yang terhubung dengan RAP pada proyek ini.', 'warning');
                }
            })
            .catch(function(err) {
                console.error('Error loading AHSPs:', err);
                ahspSelect.innerHTML = '<option value="">Gagal memuat AHSP</option>';
            });
    }

    // Load Items for selected AHSP
    function loadAhspItems(ahspIndex) {
        var ahsp = calcState.ahsps[ahspIndex];
        if (!ahsp) return;

        calcState.currentAhsp = ahsp;
        calcState.volume = ahsp.volume || 0;
        calcState.volumeUnit = ahsp.unit || 'm2';

        // Check RAP Volume
        if (!calcState.volume || calcState.volume <= 0) {
            displayVol.innerHTML = '<span class="text-danger">0 ' + calcState.volumeUnit + '</span>';
            showAlert('Volume RAP untuk item ini tidak ditemukan.', 'danger');
        } else {
            displayVol.innerText = formatDec(calcState.volume, 2) + ' ' + calcState.volumeUnit;
            clearAlert();
        }

        itemSelect.innerHTML = '<option value="">Memuat item tenaga kerja...</option>';
        itemSelect.disabled = true;

        var url = endpointUrl + '?action=get_items&project_id=' + calcState.projectId +
                  '&subcategory_id=' + (ahsp.subcategory_id || 0) +
                  '&ahsp_id=' + (ahsp.ahsp_id || 0);

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.success && res.data && res.data.items && res.data.items.length > 0) {
                    calcState.currentItems = res.data.items;
                    if (res.data.volume && (!calcState.volume || calcState.volume <= 0)) {
                        calcState.volume = res.data.volume;
                        calcState.volumeUnit = res.data.unit || calcState.volumeUnit;
                        displayVol.innerText = formatDec(calcState.volume, 2) + ' ' + calcState.volumeUnit;
                        clearAlert();
                    }

                    var html = '<option value="">-- Pilih Item --</option>';
                    res.data.items.forEach(function(it, idx) {
                        html += '<option value="' + idx + '">' + it.item_name + ' (Koef: ' + formatDec(it.coefficient, 4) + ')</option>';
                    });
                    itemSelect.innerHTML = html;
                    itemSelect.disabled = false;
                } else {
                    calcState.currentItems = [];
                    itemSelect.innerHTML = '<option value="">(Tidak ada tenaga kerja pada AHSP ini)</option>';
                    itemSelect.disabled = true;
                    showAlert('Koefisien tenaga kerja untuk item ini tidak ditemukan pada AHSP yang dipilih.', 'warning');
                }
            })
            .catch(function(err) {
                console.error('Error loading items:', err);
                itemSelect.innerHTML = '<option value="">Gagal memuat item</option>';
            });
    }

    // Select Item Event
    function onSelectItem(itemIndex) {
        var item = calcState.currentItems[itemIndex];
        if (!item) {
            calcState.selectedItem = null;
            calcState.coefficient = 0;
            displayCoeff.innerText = '-';
            inputDays.disabled = true;
            inputWorkers.disabled = true;
            btnRunCalculate.disabled = true;
            resultContainer.classList.add('d-none');
            return;
        }

        calcState.selectedItem = item;
        calcState.coefficient = item.coefficient || 0;

        if (calcState.coefficient <= 0) {
            displayCoeff.innerHTML = '<span class="text-danger">0,00</span>';
            showAlert('Koefisien tenaga kerja untuk item ini tidak ditemukan pada AHSP yang dipilih.', 'danger');
            inputDays.disabled = true;
            inputWorkers.disabled = true;
            btnRunCalculate.disabled = true;
            return;
        }

        displayCoeff.innerText = formatDec(calcState.coefficient, 4);

        if (!calcState.volume || calcState.volume <= 0) {
            showAlert('Volume RAP untuk item ini tidak ditemukan.', 'danger');
            inputDays.disabled = true;
            inputWorkers.disabled = true;
            btnRunCalculate.disabled = true;
            return;
        }

        clearAlert();
        inputDays.disabled = false;
        inputWorkers.disabled = false;
        btnRunCalculate.disabled = false;
        resultContainer.classList.add('d-none');

        // Auto-focus active input
        if (calcState.mode === 'worker') {
            inputDays.focus();
        } else {
            inputWorkers.focus();
        }
    }

    // Switch Mode
    function setMode(mode) {
        calcState.mode = mode;
        resultContainer.classList.add('d-none');

        if (mode === 'worker') {
            btnModeWorker.classList.add('active', 'shadow-sm');
            btnModeWorker.classList.remove('text-muted');
            btnModeDays.classList.remove('active', 'shadow-sm');
            btnModeDays.classList.add('text-muted');

            inputDaysContainer.classList.remove('d-none');
            inputWorkersContainer.classList.add('d-none');
            if (!inputDays.disabled) inputDays.focus();
        } else {
            btnModeDays.classList.add('active', 'shadow-sm');
            btnModeDays.classList.remove('text-muted');
            btnModeWorker.classList.remove('active', 'shadow-sm');
            btnModeWorker.classList.add('text-muted');

            inputWorkersContainer.classList.remove('d-none');
            inputDaysContainer.classList.add('d-none');
            if (!inputWorkers.disabled) inputWorkers.focus();
        }
    }

    // Execute Calculation
    function calculate() {
        clearAlert();

        if (!calcState.currentAhsp) {
            showAlert('Pilih AHSP terlebih dahulu.', 'warning');
            return;
        }
        if (!calcState.selectedItem) {
            showAlert('Pilih Item tenaga kerja terlebih dahulu.', 'warning');
            return;
        }

        var volume = parseFloat(calcState.volume);
        var coeff = parseFloat(calcState.coefficient);

        if (isNaN(volume) || volume <= 0) {
            showAlert('Volume RAP untuk item ini tidak ditemukan.', 'danger');
            return;
        }
        if (isNaN(coeff) || coeff <= 0) {
            showAlert('Koefisien tenaga kerja untuk item ini tidak ditemukan pada AHSP yang dipilih.', 'danger');
            return;
        }

        var totalOH = volume * coeff;

        if (isNaN(totalOH) || !isFinite(totalOH)) {
            showAlert('Terjadi kesalahan dalam perhitungan Total OH.', 'danger');
            return;
        }

        if (calcState.mode === 'worker') {
            var days = parseFloat(inputDays.value);
            if (isNaN(days) || days <= 0) {
                showAlert('Jumlah Hari harus lebih besar dari 0.', 'warning');
                inputDays.focus();
                return;
            }

            var rawWorkers = totalOH / days;
            if (isNaN(rawWorkers) || !isFinite(rawWorkers)) {
                showAlert('Terjadi kesalahan dalam perhitungan Kebutuhan Pekerja.', 'danger');
                return;
            }

            var actualWorkers = Math.ceil(rawWorkers);

            resultModeBadge.innerText = 'Mode: Hitung Pekerja';
            resTotalOH.innerText = formatDec(totalOH, 2) + ' OH';
            resMetric2Label.innerText = 'Kebutuhan Tenaga';
            resMetric2Value.innerText = formatDec(rawWorkers, 2) + ' orang';
            resMetric2Sub.innerText = '(' + formatDec(rawWorkers, 4) + ' orang)';
            resMetric3Label.innerText = 'Jumlah Aktual';
            resMetric3Value.innerText = actualWorkers + ' orang';
            formulaExplain.innerText = 'Rumus: Kebutuhan Pekerja = (Volume RAP: ' + formatDec(volume, 2) + ' × Koefisien: ' + formatDec(coeff, 4) + ') / ' + formatDec(days, 2) + ' Hari';

        } else {
            var workers = parseFloat(inputWorkers.value);
            if (isNaN(workers) || workers <= 0) {
                showAlert('Jumlah Pekerja harus lebih besar dari 0.', 'warning');
                inputWorkers.focus();
                return;
            }

            var rawDays = totalOH / workers;
            if (isNaN(rawDays) || !isFinite(rawDays)) {
                showAlert('Terjadi kesalahan dalam perhitungan Durasi Hari.', 'danger');
                return;
            }

            var actualDays = Math.ceil(rawDays);

            resultModeBadge.innerText = 'Mode: Hitung Hari';
            resTotalOH.innerText = formatDec(totalOH, 2) + ' OH';
            resMetric2Label.innerText = 'Estimasi Durasi';
            resMetric2Value.innerText = formatDec(rawDays, 2) + ' hari';
            resMetric2Sub.innerText = '(' + formatDec(rawDays, 4) + ' hari)';
            resMetric3Label.innerText = 'Hari Aktual';
            resMetric3Value.innerText = actualDays + ' hari';
            formulaExplain.innerText = 'Rumus: Estimasi Durasi = (Volume RAP: ' + formatDec(volume, 2) + ' × Koefisien: ' + formatDec(coeff, 4) + ') / ' + workers + ' Pekerja';
        }

        resultContainer.classList.remove('d-none');
    }

    // Event Listeners
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', function() {
            // Check if active project is specified in context or page
            var activeProject = calcState.projectId;
            if (!activeProject) {
                var projectFilterInput = document.querySelector('select[name="project_id"]');
                if (projectFilterInput && projectFilterInput.value) {
                    activeProject = parseInt(projectFilterInput.value);
                }
            }

            if (activeProject) {
                calcState.projectId = activeProject;
                projectContainer.style.display = 'none';
                loadAhsps(activeProject);
            } else {
                projectContainer.style.display = 'block';
                loadProjects();
            }

            resetCalculator(true);
        });

        projectSelect.addEventListener('change', function() {
            var pId = parseInt(this.value);
            calcState.projectId = pId || null;
            resetCalculator(true);
            if (pId) {
                loadAhsps(pId);
            }
        });

        ahspSelect.addEventListener('change', function() {
            var val = this.value;
            resetCalculator(false);
            if (val !== '') {
                loadAhspItems(parseInt(val));
            }
        });

        itemSelect.addEventListener('change', function() {
            var val = this.value;
            if (val !== '') {
                onSelectItem(parseInt(val));
            } else {
                onSelectItem(null);
            }
        });

        btnModeWorker.addEventListener('click', function() { setMode('worker'); });
        btnModeDays.addEventListener('click', function() { setMode('days'); });

        btnRunCalculate.addEventListener('click', calculate);

        inputDays.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') calculate();
        });
        inputWorkers.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') calculate();
        });

        btnReset.addEventListener('click', function() {
            resetCalculator(true);
            if (calcState.projectId) {
                loadAhsps(calcState.projectId);
            }
        });
    }

})();
</script>
