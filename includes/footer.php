                </div>
            </div>
            
            <!-- Footer -->
            <footer class="footer">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <script>document.write(new Date().getFullYear())</script> © PCM - Project Cost Management
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- JAVASCRIPT -->
    <script src="<?= $baseUrl ?>/dist/assets/libs/jquery/jquery.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/metismenu/metisMenu.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/simplebar/simplebar.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/node-waves/waves.min.js"></script>
    
    <!-- DataTables -->
    <script src="<?= $baseUrl ?>/dist/assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
    <script src="<?= $baseUrl ?>/dist/assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>
    
    <!-- Select2 -->
    <script src="<?= $baseUrl ?>/dist/assets/libs/select2/js/select2.min.js"></script>
    
    <!-- App js -->
    <script src="<?= $baseUrl ?>/dist/assets/js/app.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTables
            if ($('.datatable').length) {
                $('.datatable').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json'
                    },
                    pageLength: 25,
                    responsive: true
                });
            }
            
            // Initialize Select2
            if ($('.select2').length) {
                $('.select2').select2({
                    width: '100%'
                });
            }
            
            // Format currency input - properly handle Indonesian format (dot=thousands, comma=decimal)
            $('input.currency').on('blur', function() {
                let val = $(this).val();
                if (val) {
                    // Parse Indonesian format: remove dots (thousands), replace comma with dot (decimal)
                    let numericVal = parseFloat(val.replace(/\./g, '').replace(',', '.')) || 0;
                    // Format back to Indonesian format with 2 decimals if there was a decimal
                    if (val.indexOf(',') !== -1) {
                        $(this).val(formatNumber(numericVal, 2));
                    } else {
                        $(this).val(formatNumber(numericVal, 0));
                    }
                }
            });
            
            // Confirm delete with modal
            $(document).on('click', '.btn-delete', function(e) {
                e.preventDefault();
                var deleteUrl = $(this).attr('href');
                confirmDelete(function() {
                    window.location.href = deleteUrl;
                });
            });
            
            // Initialize Bootstrap tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
        
        // Format number helper - Indonesian format (dot for thousands, comma for decimal)
        function formatNumber(num, decimals) {
            if (num === '' || num === null || isNaN(num)) return '';
            decimals = decimals || 0;
            num = parseFloat(num);
            
            // Split integer and decimal parts
            var parts = num.toFixed(decimals).split('.');
            var intPart = parts[0];
            var decPart = parts[1] || '';
            
            // Add thousand separators (dots)
            intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            
            // Return with comma as decimal separator
            if (decimals > 0 && decPart) {
                return intPart + ',' + decPart;
            }
            return intPart;
        }
        
        // Format number with up to 4 decimals (for coefficients)
        function formatDecimal(num) {
            if (num === '' || num === null || isNaN(num)) return '';
            num = parseFloat(num);
            // Remove trailing zeros but keep up to 4 decimals
            var formatted = num.toFixed(4).replace(/\.?0+$/, '');
            // Convert to Indonesian format (comma for decimal)
            return formatted.replace('.', ',');
        }
        
        // Parse formatted number (Indonesian format to float)
        function parseFormattedNumber(str) {
            if (!str) return 0;
            // Remove thousand separators (dots) and replace comma with dot
            return parseFloat(str.toString().replace(/\./g, '').replace(',', '.')) || 0;
        }
        
        /**
         * AJAX Inline Edit Utility
         * Initialize with: inlineEditInit()
         * 
         * Required data attributes on input:
         * - data-ajax-url: URL to send AJAX request
         * - data-field: Field name to update
         * - data-id: Record ID
         * - data-action: Action name for backend
         * - data-format: 'number' | 'decimal' | 'text' (default: number)
         * - data-decimals: Number of decimal places (default: 0)
         */
        function inlineEditInit() {
            $(document).on('focus', '.inline-ajax', function() {
                var $input = $(this);
                // Store original value for comparison
                $input.data('original-value', $input.val());
                
                // If numeric, convert to raw format for editing
                var format = $input.data('format') || 'number';
                if (format === 'number' || format === 'decimal') {
                    var rawVal = parseFormattedNumber($input.val());
                    if (format === 'decimal') {
                        // Show with comma as decimal separator
                        $input.val(rawVal.toString().replace('.', ','));
                    } else {
                        $input.val(rawVal);
                    }
                }
            });
            
            $(document).on('blur', '.inline-ajax', function() {
                var $input = $(this);
                var originalValue = $input.data('original-value');
                var currentValue = $input.val();
                
                // Parse the raw value first
                var numVal = parseFormattedNumber(currentValue);
                
                // Format the display value
                var format = $input.data('format') || 'number';
                var decimals = parseInt($input.data('decimals')) || 0;
                
                if (format === 'decimal') {
                    $input.val(formatDecimal(numVal));
                } else {
                    $input.val(formatNumber(numVal, decimals));
                }
                
                // Check if value changed (compare raw values)
                var originalNumVal = parseFormattedNumber(originalValue);
                if (numVal !== originalNumVal && currentValue !== '') {
                    saveInlineEdit($input);
                }
            });
            
            $(document).on('keypress', '.inline-ajax', function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    $(this).blur();
                }
            });
        }
        
        function saveInlineEdit($input) {
            var url = $input.data('ajax-url');
            var field = $input.data('field');
            var id = $input.data('id');
            var action = $input.data('action');
            var value = parseFormattedNumber($input.val());
            
            // Show toast at bottom center
            showInlineToast('Menyimpan...', 'info');
            
            // Send AJAX request
            $.ajax({
                url: url,
                method: 'POST',
                data: {
                    action: action,
                    id: id,
                    field: field,
                    value: value
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showInlineToast('Item berhasil disimpan!', 'success');
                        
                        // Live recalculation for volume fields
                        if (field === 'volume') {
                            recalculateRowTotals($input, value);
                        }
                        
                        // Update related cells if provided by server
                        if (response.updates) {
                            $.each(response.updates, function(selector, val) {
                                $(selector).text(val);
                            });
                        }
                    } else {
                        showInlineToast(response.message || 'Gagal menyimpan', 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    showInlineToast('Error: ' + error, 'danger');
                    console.error('Inline edit error:', error);
                }
            });
        }
        
        // Recalculate row totals after volume change
        function recalculateRowTotals($input, volume) {
            var id = $input.data('id');
            var action = $input.data('action') || '';
            var unitPrice = parseFloat($input.data('unit-price')) || 0;
            var unitPriceTenaga = parseFloat($input.data('unit-price-tenaga')) || 0;
            var unitPriceBahan = parseFloat($input.data('unit-price-bahan')) || 0;
            var unitPriceAlat = parseFloat($input.data('unit-price-alat')) || 0;
            
            // Determine ID prefix based on action (RAP uses different IDs)
            var prefix = action.indexOf('rap') !== -1 ? 'rap-' : '';
            
            // Calculate new values
            var jumlah = volume * unitPrice;
            var tenaga = volume * unitPriceTenaga;
            var bahan = volume * unitPriceBahan;
            var alat = volume * unitPriceAlat;
            
            // Update the cells (try both with and without prefix)
            var jumlahId = '#jumlah-' + prefix + id;
            var tenagaId = '#tenaga-' + prefix + id;
            var bahanId = '#bahan-' + prefix + id;
            var alatId = '#alat-' + prefix + id;
            
            // Fallback to non-prefixed if prefixed doesn't exist
            if (!$(jumlahId).length) {
                jumlahId = '#jumlah-' + id;
                tenagaId = '#tenaga-' + id;
                bahanId = '#bahan-' + id;
                alatId = '#alat-' + id;
            }
            
            $(jumlahId).text(formatNumber(jumlah, 0));
            $(tenagaId).text(formatNumber(tenaga, 0));
            $(bahanId).text(formatNumber(bahan, 0));
            $(alatId).text(formatNumber(alat, 0));
            
            // Also recalculate category and grand totals
            recalculateCategoryTotals();
        }
        
        // Recalculate all category and grand totals
        function recalculateCategoryTotals() {
            // Get unique category IDs
            var categories = {};
            $('[data-category-id]').each(function() {
                var catId = $(this).data('category-id');
                if (!categories[catId]) {
                    categories[catId] = { total: 0, tenaga: 0, bahan: 0, alat: 0 };
                }
                
                // Get row values from the input
                var $input = $(this).find('.inline-ajax');
                if ($input.length) {
                    var id = $input.data('id');
                    var action = $input.data('action') || '';
                    var prefix = action.indexOf('rap') !== -1 ? 'rap-' : '';
                    
                    // Try with prefix first, fallback to without
                    var jumlahId = '#jumlah-' + prefix + id;
                    if (!$(jumlahId).length) jumlahId = '#jumlah-' + id;
                    
                    var tenagaId = '#tenaga-' + prefix + id;
                    if (!$(tenagaId).length) tenagaId = '#tenaga-' + id;
                    
                    var bahanId = '#bahan-' + prefix + id;
                    if (!$(bahanId).length) bahanId = '#bahan-' + id;
                    
                    var alatId = '#alat-' + prefix + id;
                    if (!$(alatId).length) alatId = '#alat-' + id;
                    
                    categories[catId].total += parseFormattedNumber($(jumlahId).text());
                    categories[catId].tenaga += parseFormattedNumber($(tenagaId).text());
                    categories[catId].bahan += parseFormattedNumber($(bahanId).text());
                    categories[catId].alat += parseFormattedNumber($(alatId).text());
                }
            });
            
            // Update category total rows
            $.each(categories, function(catId, totals) {
                $('#cat-total-' + catId).text(formatNumber(totals.total, 0));
                $('#cat-tenaga-' + catId).text(formatNumber(totals.tenaga, 0));
                $('#cat-bahan-' + catId).text(formatNumber(totals.bahan, 0));
                $('#cat-alat-' + catId).text(formatNumber(totals.alat, 0));
            });
            
            // Calculate and update grand totals
            var grandTotal = 0, grandTenaga = 0, grandBahan = 0, grandAlat = 0;
            $.each(categories, function(catId, totals) {
                grandTotal += totals.total;
                grandTenaga += totals.tenaga;
                grandBahan += totals.bahan;
                grandAlat += totals.alat;
            });
            
            $('#grand-total').text(formatNumber(grandTotal, 0));
            $('#grand-tenaga').text(formatNumber(grandTenaga, 0));
            $('#grand-bahan').text(formatNumber(grandBahan, 0));
            $('#grand-alat').text(formatNumber(grandAlat, 0));
            
            // Update summary totals (overhead, PPN, etc) if exist
            if ($('#summary-subtotal').length) {
                var overheadPct = parseFloat($('#summary-subtotal').data('overhead-pct')) || 10;
                var ppnPct = parseFloat($('#summary-subtotal').data('ppn-pct')) || 11;
                
                var overhead = grandTotal * (overheadPct / 100);
                var subtotal = grandTotal + overhead;
                var ppn = subtotal * (ppnPct / 100);
                var grandWithPpn = subtotal + ppn;
                
                $('#summary-subtotal').text(formatNumber(grandTotal, 0));
                $('#summary-overhead').text(formatNumber(overhead, 0));
                $('#summary-sebelum-ppn').text(formatNumber(subtotal, 0));
                $('#summary-ppn').text(formatNumber(ppn, 0));
                $('#summary-grand').text(formatNumber(grandWithPpn, 0));
            }
        }
        
        // Toast notification for inline edit - BOTTOM RIGHT position like master data
        function showInlineToast(message, type) {
            // Remove existing toast
            $('#inlineEditToast').remove();
            
            var bgClass = 'bg-' + type;
            var toast = $('<div id="inlineEditToast" class="position-fixed d-flex align-items-center text-white ' + bgClass + ' px-3 py-2 rounded shadow" style="bottom: 20px; right: 20px; z-index: 9999; min-width: 200px;">' +
                '<span class="me-2">' + message + '</span>' +
                '<button type="button" class="btn-close btn-close-white ms-auto" onclick="$(\'#inlineEditToast\').fadeOut(200, function(){ $(this).remove(); })"></button>' +
                '</div>');
            
            $('body').append(toast);
            
            // Auto hide after 2.5 seconds for success
            if (type === 'success') {
                setTimeout(function() {
                    toast.fadeOut(300, function() { $(this).remove(); });
                }, 2500);
            }
        }
        
        // Initialize inline edit on document ready
        $(document).ready(function() {
            inlineEditInit();
        });
    </script>
    
    <?php if (isset($extraScripts)): ?>
    <?= $extraScripts ?>
    <?php endif; ?>

<!-- Confirmation Modal (Reusable for delete/confirm actions) -->
<div class="modal fade" id="confirmActionModal" tabindex="-1" aria-labelledby="confirmActionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmActionModalLabel">Konfirmasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="confirmActionMessage">Apakah anda yakin?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmActionBtn">Ya</button>
            </div>
        </div>
    </div>
</div>

<script>
// Confirmation modal handler (supports custom messages)
var confirmFormToSubmit = null;
var confirmCallbackFn = null;

/**
 * Show confirmation modal
 * @param {HTMLFormElement|Function} formOrCallback - Form to submit or callback function
 * @param {Object} options - Optional settings
 * @param {string} options.message - Custom message to display
 * @param {string} options.title - Custom modal title
 * @param {string} options.buttonText - Custom button text
 * @param {string} options.buttonClass - Custom button class (e.g., 'btn-warning')
 */
function confirmDelete(formOrCallback, options) {
    options = options || {};
    
    // Set custom content
    document.getElementById('confirmActionModalLabel').textContent = options.title || 'Konfirmasi Hapus';
    document.getElementById('confirmActionMessage').innerHTML = options.message || 'Apakah anda yakin ingin menghapus?';
    
    var btn = document.getElementById('confirmActionBtn');
    btn.textContent = options.buttonText || 'Hapus';
    btn.className = 'btn ' + (options.buttonClass || 'btn-danger');
    
    if (typeof formOrCallback === 'function') {
        confirmCallbackFn = formOrCallback;
        confirmFormToSubmit = null;
    } else {
        confirmFormToSubmit = formOrCallback;
        confirmCallbackFn = null;
    }
    var modal = new bootstrap.Modal(document.getElementById('confirmActionModal'));
    modal.show();
}

// Alias for non-delete confirmations
function confirmAction(formOrCallback, options) {
    return confirmDelete(formOrCallback, options);
}

document.getElementById('confirmActionBtn').addEventListener('click', function() {
    var modal = bootstrap.Modal.getInstance(document.getElementById('confirmActionModal'));
    modal.hide();
    
    if (confirmFormToSubmit) {
        confirmFormToSubmit.submit();
    } else if (confirmCallbackFn) {
        confirmCallbackFn();
    }
});
</script>

</body>
</html>
