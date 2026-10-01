<?php
/**
 * Helper Functions
 * PCC - Project Cost Control System
 */

// Set default timezone to Asia/Jakarta (WIB)
date_default_timezone_set('Asia/Jakarta');

/**
 * Format number as Indonesian Rupiah
 * @param float $number
 * @param bool $withSymbol
 * @return string
 */
function formatRupiah($number, $withSymbol = true) {
    $formatted = number_format($number, 2, ',', '.');
    return $withSymbol ? 'Rp ' . $formatted : $formatted;
}

/**
 * Format number with thousand separator
 * @param float $number
 * @param int $decimals
 * @return string
 */
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals, ',', '.');
}

/**
 * Format volume - up to 4 decimals with a minimum of 2 decimals (trim trailing zeros beyond 2 decimals)
 * Examples: 1,0000 → 1,00; 1,5000 → 1,50; 0,0500 → 0,05; 1,2340 → 1,234; 1,2345 → 1,2345
 * @param float|string|null $number
 * @param int $minDecimals
 * @param int $maxDecimals
 * @return string
 */
function formatVolume($number, $minDecimals = 2, $maxDecimals = 4) {
    if ($number === null || $number === '') {
        $number = 0;
    }
    $number = floatval($number);
    
    // Format with max decimals first
    $formatted = number_format($number, $maxDecimals, ',', '.');
    
    // Remove trailing zeros after decimal point, keeping at least $minDecimals
    $parts = explode(',', $formatted);
    if (count($parts) === 2) {
        $decimal = rtrim($parts[1], '0');
        if (strlen($decimal) < $minDecimals) {
            $decimal = str_pad($decimal, $minDecimals, '0');
        }
        return $parts[0] . ',' . $decimal;
    }
    return $formatted;
}

/**
 * Parse Indonesian formatted number to float
 * @param string $number
 * @return float
 */
function parseNumber($number) {
    $number = str_replace('.', '', $number);
    $number = str_replace(',', '.', $number);
    return floatval($number);
}

/**
 * Format date to Indonesian format
 * @param string $date
 * @param bool $withDay
 * @return string
 */
function formatDate($date, $withDay = false) {
    if (empty($date)) return '-';
    
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    
    $timestamp = strtotime($date);
    $day = $days[date('w', $timestamp)];
    $d = date('d', $timestamp);
    $month = $months[intval(date('m', $timestamp))];
    $year = date('Y', $timestamp);
    
    if ($withDay) {
        return "$day, $d $month $year";
    }
    return "$d $month $year";
}

/**
 * Format datetime to Indonesian format with time
 * @param string $datetime
 * @param bool $withDay
 * @return string
 */
function formatDateTime($datetime, $withDay = false) {
    if (empty($datetime)) return '-';
    
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    
    $timestamp = strtotime($datetime);
    if (!$timestamp) return '-';
    
    $day = $days[date('w', $timestamp)];
    $d = date('d', $timestamp);
    $month = $months[intval(date('m', $timestamp))];
    $year = date('Y', $timestamp);
    $time = date('H:i', $timestamp);
    
    if ($withDay) {
        return "$day, $d $month $year - $time WIB";
    }
    return "$d $month $year, $time WIB";
}


/**
 * Sanitize input
 * @param string $input
 * @return string
 */
function sanitize($input) {
    return htmlspecialchars(trim((string)($input ?? '')), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate pagination
 * @param int $totalRows
 * @param int $perPage
 * @param int $currentPage
 * @param string $baseUrl
 * @return array
 */
function paginate($totalRows, $perPage = 25, $currentPage = 1, $baseUrl = '') {
    $totalPages = ceil($totalRows / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'total_rows' => $totalRows,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'base_url' => $baseUrl
    ];
}

/**
 * Render pagination HTML
 * @param array $pagination
 * @return string
 */
function renderPagination($pagination) {
    if ($pagination['total_pages'] <= 1) return '';
    
    $html = '<nav><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($pagination['current_page'] > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $pagination['base_url'] . '&page=' . ($pagination['current_page'] - 1) . '">&laquo;</a></li>';
    }
    
    // Page numbers
    $start = max(1, $pagination['current_page'] - 2);
    $end = min($pagination['total_pages'], $pagination['current_page'] + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $pagination['current_page'] ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $pagination['base_url'] . '&page=' . $i . '">' . $i . '</a></li>';
    }
    
    // Next button
    if ($pagination['current_page'] < $pagination['total_pages']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $pagination['base_url'] . '&page=' . ($pagination['current_page'] + 1) . '">&raquo;</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Generate flash message
 * @param string $type (success, error, warning, info)
 * @param string $message
 */
function setFlash($type, $message) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
    session_write_close();
}

/**
 * Get and clear flash message
 * @return array|null
 */
function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        unset($_SESSION['flash']);
        session_write_close();
        return $flash;
    }
    return null;
}

/**
 * Render flash message HTML
 * @return string
 */
function renderFlash() {
    $flash = getFlash();
    if (!$flash) return '';
    
    $alertClass = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info'
    ];
    
    $class = $alertClass[$flash['type']] ?? 'alert-info';
    return '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">
        ' . $flash['message'] . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

/**
 * Get status badge HTML
 * @param string $status
 * @return string
 */
function getStatusBadge($status) {
    $badges = [
        'draft' => '<span class="badge bg-secondary">Draft</span>',
        'on_progress' => '<span class="badge bg-primary">On Progress</span>',
        'completed' => '<span class="badge bg-success">Completed</span>',
        'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
        'pm_approved' => '<span class="badge bg-info">PM Approved</span>',
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
}

/**
 * Convert 1-based index to letter code (1 -> A, 26 -> Z, 27 -> AA, 28 -> AB, etc.)
 * @param int $index
 * @return string
 */
function getCategoryCodeFromIndex($index) {
    $index = intval($index);
    if ($index <= 0) return 'A';
    $code = '';
    while ($index > 0) {
        $index--;
        $code = chr(65 + ($index % 26)) . $code;
        $index = intdiv($index, 26);
    }
    return $code;
}

/**
 * Get next RAB Category letter code (A -> B, Z -> AA, AA -> AB, etc.)
 * @param string|null $currentCode
 * @return string
 */
function getNextCategoryCode($currentCode = null) {
    if (empty($currentCode)) {
        return 'A';
    }
    
    $code = strtoupper(trim($currentCode));
    if (preg_match('/^[A-Z]+$/', $code)) {
        return ++$code;
    }
    
    $cleaned = preg_replace('/[^A-Z]/', '', $code);
    if (!empty($cleaned)) {
        return ++$cleaned;
    }
    
    return 'A';
}

/**
 * Get detailed status badge with PM & Admin PIC symbols and tooltips
 * @param array|string $request (Request data array or status string)
 * @return string HTML
 */
function getDetailedStatusBadge($request) {
    if (is_string($request)) {
        return getStatusBadge($request);
    }
    
    if (!is_array($request)) {
        return getStatusBadge('pending');
    }
    
    $status = $request['status'] ?? 'pending';
    
    $pmName = $request['pm_approved_by_name'] ?? '';
    $pmAt = !empty($request['pm_approved_at']) ? formatDateTime($request['pm_approved_at']) : '';
    $pmNotes = $request['pm_notes'] ?? '';
    
    $approverName = $request['approved_by_name'] ?? '';
    $approverRole = $request['approved_by_role'] ?? '';
    $approvedAt = !empty($request['approved_at']) ? formatDateTime($request['approved_at']) : '';
    $adminNotes = $request['admin_notes'] ?? '';
    
    // PM PIC Status defaults
    $pmBadgeClass = 'bg-secondary text-white';
    $pmSymbolText = '-';
    $pmTooltip = 'PM: Belum diproses';
    
    // Admin PIC Status defaults
    $adminBadgeClass = 'bg-secondary text-white';
    $adminSymbolText = '-';
    $adminTooltip = 'Admin: Belum diproses';
    
    if ($status === 'pending') {
        // Pending
        $pmBadgeClass = 'bg-warning text-dark';
        $pmSymbolText = 'Pending';
        $pmTooltip = 'PM: Menunggu persetujuan / verifikasi PM';
        
        $adminBadgeClass = 'bg-secondary text-white';
        $adminSymbolText = 'Pending';
        $adminTooltip = 'Admin: Menunggu persetujuan Admin (setelah review PM)';
        
    } elseif ($status === 'pm_approved') {
        // PM Approved (waiting for Admin)
        $pmBadgeClass = 'bg-info text-white';
        $pmSymbolText = 'Verif';
        $pmTooltip = 'PM: Disetujui / Diverifikasi oleh PM' . ($pmName ? ' (' . sanitize($pmName) . ')' : '') . ($pmAt ? ' tgl ' . $pmAt : '');
        if ($pmNotes) {
            $pmTooltip .= ' - Catatan: ' . sanitize($pmNotes);
        }
        
        $adminBadgeClass = 'bg-warning text-dark';
        $adminSymbolText = 'Pending';
        $adminTooltip = 'Admin: Menunggu persetujuan Admin';
        
    } elseif ($status === 'approved') {
        // Fully Approved by Admin
        if (!empty($request['pm_approved_by']) || !empty($pmName)) {
            // Verified by PM first, then Approved by Admin
            $pmBadgeClass = 'bg-info text-white';
            $pmSymbolText = 'Verif';
            $pmTooltip = 'PM: Sudah diverifikasi oleh PM' . ($pmName ? ' (' . sanitize($pmName) . ')' : '') . ($pmAt ? ' tgl ' . $pmAt : '');
            if ($pmNotes) {
                $pmTooltip .= ' - Catatan: ' . sanitize($pmNotes);
            }
            
            $adminBadgeClass = 'bg-success text-white';
            $adminSymbolText = 'Approved';
            $adminTooltip = 'Admin: Disetujui oleh Admin' . ($approverName ? ' (' . sanitize($approverName) . ')' : '') . ($approvedAt ? ' tgl ' . $approvedAt : '');
            if ($adminNotes) {
                $adminTooltip .= ' - Catatan: ' . sanitize($adminNotes);
            }
        } else {
            // Directly Approved by Admin (Skipped PM)
            $pmBadgeClass = 'bg-secondary text-white';
            $pmSymbolText = 'Direct';
            $pmTooltip = 'PM: Tanpa verifikasi PM (Disetujui langsung oleh Admin)';
            
            $adminBadgeClass = 'bg-success text-white';
            $adminSymbolText = 'Approved';
            $adminTooltip = 'Admin: Disetujui langsung oleh Admin' . ($approverName ? ' (' . sanitize($approverName) . ')' : '') . ($approvedAt ? ' tgl ' . $approvedAt : '');
            if ($adminNotes) {
                $adminTooltip .= ' - Catatan: ' . sanitize($adminNotes);
            }
        }
        
    } elseif ($status === 'rejected') {
        // Rejected
        $isRejectedByPm = ($approverRole === 'project_manager') || (!empty($request['approved_by']) && !empty($request['pm_approved_by']) && $request['pm_approved_by'] == $request['approved_by']);
        
        if ($isRejectedByPm) {
            // Rejected by PM
            $pmBadgeClass = 'bg-danger text-white';
            $pmSymbolText = 'Rejected';
            $pmTooltip = 'PM: Ditolak oleh PM' . ($approverName ? ' (' . sanitize($approverName) . ')' : '') . ($approvedAt ? ' tgl ' . $approvedAt : '');
            if ($adminNotes) {
                $pmTooltip .= ' - Catatan: ' . sanitize($adminNotes);
            } elseif ($pmNotes) {
                $pmTooltip .= ' - Catatan: ' . sanitize($pmNotes);
            }
            
            $adminBadgeClass = 'bg-secondary text-white';
            $adminSymbolText = '-';
            $adminTooltip = 'Admin: Dibatalkan / Ditolak di tingkat PM';
        } else {
            // Rejected by Admin
            if (!empty($request['pm_approved_by']) || !empty($pmName)) {
                // Was approved by PM, but rejected by Admin
                $pmBadgeClass = 'bg-info text-white';
                $pmSymbolText = 'Verif';
                $pmTooltip = 'PM: Sempat disetujui oleh PM' . ($pmName ? ' (' . sanitize($pmName) . ')' : '') . ($pmAt ? ' tgl ' . $pmAt : '');
                
                $adminBadgeClass = 'bg-danger text-white';
                $adminSymbolText = 'Rejected';
                $adminTooltip = 'Admin: Ditolak oleh Admin' . ($approverName ? ' (' . sanitize($approverName) . ')' : '') . ($approvedAt ? ' tgl ' . $approvedAt : '');
                if ($adminNotes) {
                    $adminTooltip .= ' - Catatan: ' . sanitize($adminNotes);
                }
            } else {
                // Rejected directly by Admin before PM review
                $pmBadgeClass = 'bg-secondary text-white';
                $pmSymbolText = '-';
                $pmTooltip = 'PM: Belum sempat diproses PM (Ditolak langsung oleh Admin)';
                
                $adminBadgeClass = 'bg-danger text-white';
                $adminSymbolText = 'Rejected';
                $adminTooltip = 'Admin: Ditolak langsung oleh Admin' . ($approverName ? ' (' . sanitize($approverName) . ')' : '') . ($approvedAt ? ' tgl ' . $approvedAt : '');
                if ($adminNotes) {
                    $adminTooltip .= ' - Catatan: ' . sanitize($adminNotes);
                }
            }
        }
    }
    
    $mainBadge = getStatusBadge($status);
    
    $html = '<div class="d-inline-flex flex-column align-items-start gap-1">';
    $html .= '  <div class="d-flex gap-1 align-items-center mb-1" style="font-size: 0.7rem; line-height: 1.2;">';
    $html .= '    <span class="badge ' . $pmBadgeClass . ' px-1 py-1" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $pmTooltip . '" style="cursor: pointer;">';
    $html .= '      <i class="mdi mdi-account-tie me-1"></i>PM: ' . $pmSymbolText;
    $html .= '    </span>';
    $html .= '    <span class="badge ' . $adminBadgeClass . ' px-1 py-1" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $adminTooltip . '" style="cursor: pointer;">';
    $html .= '      <i class="mdi mdi-shield-account me-1"></i>Admin: ' . $adminSymbolText;
    $html .= '    </span>';
    $html .= '  </div>';
    $html .= '  <div>' . $mainBadge . '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Get quality badge HTML
 * @param string $quality
 * @return string
 */
function getQualityBadge($quality) {
    $badges = [
        'top' => '<span class="badge bg-success">Top</span>',
        'mid' => '<span class="badge bg-primary">Mid</span>',
        'low' => '<span class="badge bg-warning text-dark">Low</span>',
        'bad' => '<span class="badge bg-danger">Bad</span>'
    ];
    return $badges[$quality] ?? '<span class="badge bg-secondary">' . ucfirst($quality) . '</span>';
}

/**
 * Get item type label
 * @param string $type
 * @return string
 */
function getItemTypeLabel($type) {
    $labels = [
        'labor' => 'Tenaga',
        'material' => 'Bahan',
        'equipment' => 'Peralatan'
    ];
    return $labels[$type] ?? ucfirst($type);
}

/**
 * Calculate price difference percentage
 * @param float $fieldPrice
 * @param float $rapPrice
 * @return array [percentage, isOverBudget]
 */
function calculatePriceDiff($fieldPrice, $rapPrice) {
    if ($rapPrice <= 0) return [0, false];
    
    $diff = (($fieldPrice - $rapPrice) / $rapPrice) * 100;
    return [round($diff, 2), $fieldPrice > $rapPrice];
}

/**
 * Get price comparison label
 * @param float $fieldPrice
 * @param float $rapPrice
 * @return string
 */
function getPriceComparisonLabel($fieldPrice, $rapPrice) {
    list($diff, $isOver) = calculatePriceDiff($fieldPrice, $rapPrice);
    
    if ($isOver) {
        return '<span class="badge bg-danger">LEBIH MAHAL ' . abs($diff) . '%</span>';
    } else if ($diff < 0) {
        return '<span class="badge bg-success">HEMAT ' . abs($diff) . '%</span>';
    }
    return '<span class="badge bg-success">AMAN</span>';
}

/**
 * Generate unique request number
 * @param int $projectId
 * @return string
 */
function generateRequestNumber($projectId) {
    $count = dbGetRow("SELECT COUNT(*) as cnt FROM requests WHERE project_id = ?", [$projectId]);
    $num = ($count['cnt'] ?? 0) + 1;
    return 'REQ-' . $projectId . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate weekly date ranges for a project
 * Week 1: start_date to nearest Sunday
 * Week 2+: Monday to Sunday
 * Continues until end_date
 * 
 * @param string $startDate Project start date (Y-m-d)
 * @param int $durationDays Duration in days
 * @return array Array of weeks with week_number, start, end, label
 */
function generateWeeklyRanges($startDate, $durationDays) {
    if (empty($startDate) || !$durationDays) return [];
    
    $weeks = [];
    $start = new DateTime($startDate);
    $end = clone $start;
    $end->modify("+{$durationDays} days");
    $weekNum = 1;
    
    // Week 1: start_date to nearest Sunday
    $firstSunday = clone $start;
    $dayOfWeek = (int)$start->format('w'); // 0=Sunday, 1=Monday, etc.
    
    if ($dayOfWeek !== 0) {
        // Find next Sunday
        $daysUntilSunday = 7 - $dayOfWeek;
        $firstSunday->modify("+{$daysUntilSunday} days");
    }
    
    // Ensure first week doesn't exceed end_date
    if ($firstSunday > $end) {
        $firstSunday = clone $end;
    }
    
    $weeks[] = [
        'week_number' => $weekNum,
        'start' => $start->format('Y-m-d'),
        'end' => $firstSunday->format('Y-m-d'),
        'label' => formatWeekRangeLabel($start, $firstSunday)
    ];
    
    // Week 2+: Monday to Sunday
    $current = clone $firstSunday;
    $current->modify('+1 day'); // Move to Monday
    
    while ($current <= $end) {
        $weekNum++;
        $weekStart = clone $current;
        $weekEnd = clone $current;
        $weekEnd->modify('+6 days'); // Move to Sunday
        
        // Cap at end_date
        if ($weekEnd > $end) {
            $weekEnd = clone $end;
        }
        
        $weeks[] = [
            'week_number' => $weekNum,
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d'),
            'label' => formatWeekRangeLabel($weekStart, $weekEnd)
        ];
        
        $current->modify('+7 days'); // Move to next Monday
    }
    
    return $weeks;
}

/**
 * Format week range label for display
 * @param string|DateTime $start
 * @param string|DateTime $end
 * @return string e.g. "26 Jan - 1 Feb 2026"
 */
function formatWeekRangeLabel($start, $end) {
    $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    
    if (is_string($start)) {
        $start = new DateTime($start);
    }
    if (is_string($end)) {
        $end = new DateTime($end);
    }
    
    $startDay = $start->format('j');
    $startMonth = $months[(int)$start->format('n')];
    $endDay = $end->format('j');
    $endMonth = $months[(int)$end->format('n')];
    $endYear = $end->format('Y');
    
    if ($start->format('n') === $end->format('n')) {
        return "{$startDay} - {$endDay} {$endMonth} {$endYear}";
    }
    return "{$startDay} {$startMonth} - {$endDay} {$endMonth} {$endYear}";
}

/**
 * Check if project is locked (on_progress or completed)
 * @param array $project Project data
 * @return bool
 */
function isProjectLocked($project) {
    return in_array($project['status'] ?? '', ['on_progress', 'completed']);
}

// ============================================
// MASTER DATA RAP FUNCTIONS
// ============================================

/**
 * Initialize RAP Master Data by copying from RAB Master Data
 * Called when first accessing RAP Master Data for a project
 * @param int $projectId
 * @return bool
 */
function initRapMasterData($projectId) {
    // Check if already initialized
    $project = dbGetRow("SELECT rap_master_data_initialized FROM projects WHERE id = ?", [$projectId]);
    if ($project && $project['rap_master_data_initialized']) {
        return true;
    }
    
    $rabItems = dbGetAll("SELECT * FROM project_items WHERE project_id = ?", [$projectId]);
    foreach ($rabItems as $item) {
        $existing = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", 
            [$projectId, $item['item_code']]);
        if (!$existing) {
            dbInsert("INSERT INTO project_items_rap (project_id, item_code, name, brand, category, unit, price, actual_price, rab_item_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$projectId, $item['item_code'], $item['name'], $item['brand'], $item['category'], $item['unit'], $item['price'], $item['actual_price'], $item['id']]);
        }
    }
    
    $itemMapping = [];
    $rapItems = dbGetAll("SELECT id, item_code FROM project_items_rap WHERE project_id = ?", [$projectId]);
    foreach ($rabItems as $rabItem) {
        foreach ($rapItems as $rapItem) {
            if ($rabItem['item_code'] === $rapItem['item_code']) {
                $itemMapping[$rabItem['id']] = $rapItem['id'];
                break;
            }
        }
    }
    
    $rabAhsps = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ?", [$projectId]);
    foreach ($rabAhsps as $ahsp) {
        $existing = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", 
            [$projectId, $ahsp['ahsp_code']]);
        if (!$existing) {
            $rapAhspId = dbInsert("INSERT INTO project_ahsp_rap (project_id, ahsp_code, work_name, unit, unit_price, rab_ahsp_id) VALUES (?, ?, ?, ?, ?, ?)",
                [$projectId, $ahsp['ahsp_code'], $ahsp['work_name'], $ahsp['unit'], $ahsp['unit_price'], $ahsp['id']]);
            
            $rabDetails = dbGetAll("SELECT * FROM project_ahsp_details WHERE ahsp_id = ?", [$ahsp['id']]);
            foreach ($rabDetails as $detail) {
                $rapItemId = $itemMapping[$detail['item_id']] ?? null;
                if ($rapItemId) {
                    dbInsert("INSERT INTO project_ahsp_details_rap (ahsp_id, item_id, coefficient, unit_price) VALUES (?, ?, ?, ?)",
                        [$rapAhspId, $rapItemId, $detail['coefficient'], $detail['unit_price']]);
                }
            }
        }
    }
    
    dbExecute("UPDATE projects SET rap_master_data_initialized = 1 WHERE id = ?", [$projectId]);
    
    return true;
}

/**
 * Sync item code from RAB to RAP (when adding new item in RAB)
 * @param int $projectId
 * @param string $itemCode
 */
function syncItemCodeRabToRap($projectId, $itemCode) {
    $rabItem = dbGetRow("SELECT * FROM project_items WHERE project_id = ? AND item_code = ?", [$projectId, $itemCode]);
    if (!$rabItem) return;
    
    $existing = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", [$projectId, $itemCode]);
    if (!$existing) {
        dbInsert("INSERT INTO project_items_rap (project_id, item_code, name, brand, category, unit, price, actual_price, rab_item_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$projectId, $itemCode, $rabItem['name'], $rabItem['brand'], $rabItem['category'], $rabItem['unit'], $rabItem['price'], $rabItem['actual_price'], $rabItem['id']]);
    }
}

/**
 * Sync item code from RAP to RAB (when adding new item in RAP)
 * @param int $projectId
 * @param string $itemCode
 */
function syncItemCodeRapToRab($projectId, $itemCode) {
    $rapItem = dbGetRow("SELECT * FROM project_items_rap WHERE project_id = ? AND item_code = ?", [$projectId, $itemCode]);
    if (!$rapItem) return;
    
    $existing = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", [$projectId, $itemCode]);
    if (!$existing) {
        $rabItemId = dbInsert("INSERT INTO project_items (project_id, item_code, name, brand, category, unit, price, actual_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$projectId, $itemCode, $rapItem['name'], $rapItem['brand'], $rapItem['category'], $rapItem['unit'], $rapItem['price'], $rapItem['actual_price']]);
        
        dbExecute("UPDATE project_items_rap SET rab_item_id = ? WHERE id = ?", [$rabItemId, $rapItem['id']]);
    }
}

/**
 * Check if item code is duplicate in RAB or RAP within the project
 * @param int $projectId
 * @param string $itemCode
 * @param int|null $excludeRabId
 * @param int|null $excludeRapId
 * @return bool
 */
function isItemCodeDuplicate($projectId, $itemCode, $excludeRabId = null, $excludeRapId = null) {
    $itemCode = trim($itemCode);
    if (empty($itemCode)) return false;
    
    $sqlRab = "SELECT id FROM project_items WHERE project_id = ? AND LOWER(item_code) = LOWER(?)";
    $paramsRab = [$projectId, $itemCode];
    if ($excludeRabId) {
        $sqlRab .= " AND id != ?";
        $paramsRab[] = $excludeRabId;
    }
    if (dbGetRow($sqlRab, $paramsRab)) return true;
    
    $sqlRap = "SELECT id FROM project_items_rap WHERE project_id = ? AND LOWER(item_code) = LOWER(?)";
    $paramsRap = [$projectId, $itemCode];
    if ($excludeRapId) {
        $sqlRap .= " AND id != ?";
        $paramsRap[] = $excludeRapId;
    }
    if (dbGetRow($sqlRap, $paramsRap)) return true;
    
    return false;
}

/**
 * Check if AHSP code is duplicate in RAB or RAP within the project
 * @param int $projectId
 * @param string $ahspCode
 * @param int|null $excludeRabId
 * @param int|null $excludeRapId
 * @return bool
 */
function isAhspCodeDuplicate($projectId, $ahspCode, $excludeRabId = null, $excludeRapId = null) {
    $ahspCode = trim($ahspCode);
    if (empty($ahspCode)) return false;
    
    $sqlRab = "SELECT id FROM project_ahsp WHERE project_id = ? AND LOWER(ahsp_code) = LOWER(?)";
    $paramsRab = [$projectId, $ahspCode];
    if ($excludeRabId) {
        $sqlRab .= " AND id != ?";
        $paramsRab[] = $excludeRabId;
    }
    if (dbGetRow($sqlRab, $paramsRab)) return true;
    
    $sqlRap = "SELECT id FROM project_ahsp_rap WHERE project_id = ? AND LOWER(ahsp_code) = LOWER(?)";
    $paramsRap = [$projectId, $ahspCode];
    if ($excludeRapId) {
        $sqlRap .= " AND id != ?";
        $paramsRap[] = $excludeRapId;
    }
    if (dbGetRow($sqlRap, $paramsRap)) return true;
    
    return false;
}

/**
 * Sync: When editing RAB item (code, name, brand, category, unit), mirror to RAP
 * NOTE: Price and actual_price remain separate/independent!
 * @param int $itemId RAB Item ID
 * @param string $itemCode
 * @param string $name
 * @param string|null $brand
 * @param string $category
 * @param string $unit
 * @param int $projectId
 */
function syncEditItemRabToRap($itemId, $itemCode, $name, $brand, $category, $unit, $projectId) {
    $oldRab = dbGetRow("SELECT item_code FROM project_items WHERE id = ?", [$itemId]);
    $oldCode = $oldRab ? $oldRab['item_code'] : $itemCode;
    
    $rapItem = dbGetRow("
        SELECT id FROM project_items_rap 
        WHERE project_id = ? AND (rab_item_id = ? OR LOWER(item_code) = LOWER(?) OR LOWER(item_code) = LOWER(?))
    ", [$projectId, $itemId, $itemCode, $oldCode]);
    
    if ($rapItem) {
        dbExecute("
            UPDATE project_items_rap 
            SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?, rab_item_id = ?
            WHERE id = ? AND project_id = ?
        ", [$itemCode, $name, $brand ?: null, $category, $unit, $itemId, $rapItem['id'], $projectId]);
    } else {
        $rabItem = dbGetRow("SELECT price, actual_price FROM project_items WHERE id = ?", [$itemId]);
        $defaultPrice = $rabItem ? $rabItem['price'] : 0;
        $defaultActual = $rabItem ? $rabItem['actual_price'] : null;
        
        dbInsert("
            INSERT INTO project_items_rap (project_id, item_code, name, brand, category, unit, price, actual_price, rab_item_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$projectId, $itemCode, $name, $brand ?: null, $category, $unit, $defaultPrice, $defaultActual, $itemId]);
    }
}

/**
 * Sync: When editing RAP item (code, name, brand, category, unit), mirror to RAB
 * NOTE: Price and actual_price remain separate/independent!
 * @param int $rapItemId RAP Item ID
 * @param string $itemCode
 * @param string $name
 * @param string|null $brand
 * @param string $category
 * @param string $unit
 * @param int $projectId
 */
function syncEditItemRapToRab($rapItemId, $itemCode, $name, $brand, $category, $unit, $projectId) {
    $rapItem = dbGetRow("SELECT rab_item_id, item_code, price, actual_price FROM project_items_rap WHERE id = ?", [$rapItemId]);
    $rabItemId = $rapItem ? $rapItem['rab_item_id'] : null;
    $oldCode = $rapItem ? $rapItem['item_code'] : $itemCode;
    
    $rabItem = null;
    if ($rabItemId) {
        $rabItem = dbGetRow("SELECT id FROM project_items WHERE id = ? AND project_id = ?", [$rabItemId, $projectId]);
    }
    if (!$rabItem) {
        $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND (LOWER(item_code) = LOWER(?) OR LOWER(item_code) = LOWER(?))", [$projectId, $itemCode, $oldCode]);
    }
    
    if ($rabItem) {
        dbExecute("
            UPDATE project_items 
            SET item_code = ?, name = ?, brand = ?, category = ?, unit = ?
            WHERE id = ? AND project_id = ?
        ", [$itemCode, $name, $brand ?: null, $category, $unit, $rabItem['id'], $projectId]);
        
        dbExecute("UPDATE project_items_rap SET rab_item_id = ? WHERE id = ?", [$rabItem['id'], $rapItemId]);
    } else {
        $defaultPrice = $rapItem ? $rapItem['price'] : 0;
        $defaultActual = $rapItem ? $rapItem['actual_price'] : null;
        
        $newRabId = dbInsert("
            INSERT INTO project_items (project_id, item_code, name, brand, category, unit, price, actual_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$projectId, $itemCode, $name, $brand ?: null, $category, $unit, $defaultPrice, $defaultActual]);
        
        dbExecute("UPDATE project_items_rap SET rab_item_id = ? WHERE id = ?", [$newRabId, $rapItemId]);
    }
}

/**
 * Sync: When deleting RAB item, also delete matching RAP item and related AHSP details
 * @param int $itemId RAB Item ID
 * @param int $projectId
 */
function syncDeleteItemRabToRap($itemId, $projectId) {
    $rabItem = dbGetRow("SELECT * FROM project_items WHERE id = ? AND project_id = ?", [$itemId, $projectId]);
    if (!$rabItem) return;
    
    $affectedRabAhsp = dbGetAll("SELECT DISTINCT ahsp_id FROM project_ahsp_details WHERE item_id = ?", [$itemId]);
    dbExecute("DELETE FROM project_ahsp_details WHERE item_id = ?", [$itemId]);
    
    $rapItem = dbGetRow("
        SELECT id FROM project_items_rap 
        WHERE project_id = ? AND (rab_item_id = ? OR LOWER(item_code) = LOWER(?))
    ", [$projectId, $itemId, $rabItem['item_code']]);
    
    $affectedRapAhsp = [];
    if ($rapItem) {
        $affectedRapAhsp = dbGetAll("SELECT DISTINCT ahsp_id FROM project_ahsp_details_rap WHERE item_id = ?", [$rapItem['id']]);
        dbExecute("DELETE FROM project_ahsp_details_rap WHERE item_id = ?", [$rapItem['id']]);
        dbExecute("DELETE FROM project_items_rap WHERE id = ?", [$rapItem['id']]);
    }
    
    dbExecute("DELETE FROM project_items WHERE id = ? AND project_id = ?", [$itemId, $projectId]);
    
    foreach ($affectedRabAhsp as $row) {
        recalculateAhspPrice($row['ahsp_id']);
    }
    foreach ($affectedRapAhsp as $row) {
        recalculateRapAhspPrice($row['ahsp_id']);
        syncMasterAhspRapToRapTable($row['ahsp_id'], $projectId);
    }
}

/**
 * Sync: When deleting RAP item, also delete matching RAB item and related AHSP details
 * @param int $rapItemId RAP Item ID
 * @param int $projectId
 */
function syncDeleteItemRapToRab($rapItemId, $projectId) {
    $rapItem = dbGetRow("SELECT * FROM project_items_rap WHERE id = ? AND project_id = ?", [$rapItemId, $projectId]);
    if (!$rapItem) return;
    
    $affectedRapAhsp = dbGetAll("SELECT DISTINCT ahsp_id FROM project_ahsp_details_rap WHERE item_id = ?", [$rapItemId]);
    dbExecute("DELETE FROM project_ahsp_details_rap WHERE item_id = ?", [$rapItemId]);
    
    $rabItem = null;
    if ($rapItem['rab_item_id']) {
        $rabItem = dbGetRow("SELECT id FROM project_items WHERE id = ? AND project_id = ?", [$rapItem['rab_item_id'], $projectId]);
    }
    if (!$rabItem) {
        $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND LOWER(item_code) = LOWER(?)", [$projectId, $rapItem['item_code']]);
    }
    
    $affectedRabAhsp = [];
    if ($rabItem) {
        $affectedRabAhsp = dbGetAll("SELECT DISTINCT ahsp_id FROM project_ahsp_details WHERE item_id = ?", [$rabItem['id']]);
        dbExecute("DELETE FROM project_ahsp_details WHERE item_id = ?", [$rabItem['id']]);
        dbExecute("DELETE FROM project_items WHERE id = ?", [$rabItem['id']]);
    }
    
    dbExecute("DELETE FROM project_items_rap WHERE id = ? AND project_id = ?", [$rapItemId, $projectId]);
    
    foreach ($affectedRapAhsp as $row) {
        recalculateRapAhspPrice($row['ahsp_id']);
        syncMasterAhspRapToRapTable($row['ahsp_id'], $projectId);
    }
    foreach ($affectedRabAhsp as $row) {
        recalculateAhspPrice($row['ahsp_id']);
    }
}

/**
 * Sync AHSP code from RAB to RAP (when adding new AHSP in RAB)
 * @param int $projectId
 * @param string $ahspCode
 */
function syncAhspCodeRabToRap($projectId, $ahspCode) {
    $rabAhsp = dbGetRow("SELECT * FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", [$projectId, $ahspCode]);
    if (!$rabAhsp) return;
    
    $existing = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", [$projectId, $ahspCode]);
    if (!$existing) {
        $rapAhspId = dbInsert("INSERT INTO project_ahsp_rap (project_id, ahsp_code, work_name, unit, unit_price, rab_ahsp_id) VALUES (?, ?, ?, ?, ?, ?)",
            [$projectId, $ahspCode, $rabAhsp['work_name'], $rabAhsp['unit'], $rabAhsp['unit_price'], $rabAhsp['id']]);
        
        $rabDetails = dbGetAll("SELECT * FROM project_ahsp_details WHERE ahsp_id = ?", [$rabAhsp['id']]);
        foreach ($rabDetails as $detail) {
            $rabItem = dbGetRow("SELECT item_code FROM project_items WHERE id = ?", [$detail['item_id']]);
            if ($rabItem) {
                $rapItem = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", [$projectId, $rabItem['item_code']]);
                if ($rapItem) {
                    dbInsert("INSERT INTO project_ahsp_details_rap (ahsp_id, item_id, coefficient, unit_price) VALUES (?, ?, ?, ?)",
                        [$rapAhspId, $rapItem['id'], $detail['coefficient'], $detail['unit_price']]);
                }
            }
        }
    }
}

/**
 * Sync AHSP code from RAP to RAB (when adding new AHSP in RAP)
 * @param int $projectId
 * @param string $ahspCode
 */
function syncAhspCodeRapToRab($projectId, $ahspCode) {
    $rapAhsp = dbGetRow("SELECT * FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", [$projectId, $ahspCode]);
    if (!$rapAhsp) return;
    
    $existing = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", [$projectId, $ahspCode]);
    if (!$existing) {
        $rabAhspId = dbInsert("INSERT INTO project_ahsp (project_id, ahsp_code, work_name, unit, unit_price) VALUES (?, ?, ?, ?, ?)",
            [$projectId, $ahspCode, $rapAhsp['work_name'], $rapAhsp['unit'], $rapAhsp['unit_price']]);
        
        dbExecute("UPDATE project_ahsp_rap SET rab_ahsp_id = ? WHERE id = ?", [$rabAhspId, $rapAhsp['id']]);
        
        $rapDetails = dbGetAll("SELECT * FROM project_ahsp_details_rap WHERE ahsp_id = ?", [$rapAhsp['id']]);
        foreach ($rapDetails as $detail) {
            $rapItem = dbGetRow("SELECT item_code FROM project_items_rap WHERE id = ?", [$detail['item_id']]);
            if ($rapItem) {
                $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", [$projectId, $rapItem['item_code']]);
                if ($rabItem) {
                    dbInsert("INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient, unit_price) VALUES (?, ?, ?, ?)",
                        [$rabAhspId, $rabItem['id'], $detail['coefficient'], $detail['unit_price']]);
                }
            }
        }
    }
}

/**
 * Recalculate RAP AHSP price from its components
 * @param int $ahspId AHSP ID in project_ahsp_rap table
 */
function recalculateRapAhspPrice($ahspId) {
    $total = dbGetRow("
        SELECT COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
        FROM project_ahsp_details_rap d
        JOIN project_items_rap i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
    ", [$ahspId]);
    
    $unitPrice = $total['total'] ?? 0;
    dbExecute("UPDATE project_ahsp_rap SET unit_price = ? WHERE id = ?", [$unitPrice, $ahspId]);
    
    return $unitPrice;
}

/**
 * Sync RAP item changes to rap_ahsp_details (update price)
 * @param int $itemId Item ID in project_items_rap table
 * @param int $projectId
 */
function syncRapItemToAhsp($itemId, $projectId) {
    $item = dbGetRow("SELECT price FROM project_items_rap WHERE id = ?", [$itemId]);
    if (!$item) return;
    
    // Clear any stale override unit_price in details so it dynamically uses new master price
    dbExecute("UPDATE project_ahsp_details_rap SET unit_price = NULL WHERE item_id = ?", [$itemId]);
    
    $ahspIds = dbGetAll("
        SELECT DISTINCT d.ahsp_id 
        FROM project_ahsp_details_rap d
        JOIN project_ahsp_rap pa ON d.ahsp_id = pa.id
        WHERE d.item_id = ? AND pa.project_id = ?
    ", [$itemId, $projectId]);
    
    foreach ($ahspIds as $row) {
        recalculateRapAhspPrice($row['ahsp_id']);
        syncMasterAhspRapToRapTable($row['ahsp_id'], $projectId);
    }
}

/**
 * Get RAP AHSP component breakdown from Master Data RAP tables
 * Uses AHSP code matching to get data from project_ahsp_rap / project_ahsp_details_rap
 * @param string $ahspCode
 * @param int $projectId
 * @return array ['upah' => float, 'material' => float, 'alat' => float]
 */
if (!function_exists('getRapAhspComponentBreakdown')) {
    function getRapAhspComponentBreakdown($ahspCode, $projectId) {
        $result = ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
        
        if (!$ahspCode) return $result;
        
        // Find matching AHSP RAP by code
        $ahspRap = dbGetRow("
            SELECT id FROM project_ahsp_rap 
            WHERE project_id = ? AND ahsp_code = ?
        ", [$projectId, $ahspCode]);
        
        if (!$ahspRap) return $result;
        
        // Get component breakdown from project_ahsp_details_rap
        $totals = dbGetAll("
            SELECT i.category, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
            FROM project_ahsp_details_rap d
            JOIN project_items_rap i ON d.item_id = i.id
            WHERE d.ahsp_id = ?
            GROUP BY i.category
        ", [$ahspRap['id']]);
        
        foreach ($totals as $row) {
            $cat = $row['category'] ?? '';
            if (isset($result[$cat])) {
                $result[$cat] = floatval($row['total']);
            }
        }
        
        return $result;
    }
}

/**
 * Sync Master Data AHSP RAP to RAP Table (rap_ahsp_details)
 * Called when editing coefficient/price in Master Data AHSP RAP
 * 
 * @param int $ahspRapId AHSP ID in project_ahsp_rap table
 * @param int $projectId
 */
function syncMasterAhspRapToRapTable($ahspRapId, $projectId) {
    $ahspRap = dbGetRow("SELECT * FROM project_ahsp_rap WHERE id = ?", [$ahspRapId]);
    if (!$ahspRap) return;
    
    $rapItems = dbGetAll("
        SELECT ri.id as rap_item_id, rs.id as subcategory_id
        FROM rap_items ri
        JOIN rab_subcategories rs ON ri.subcategory_id = rs.id
        JOIN rab_categories rc ON rs.category_id = rc.id
        WHERE rs.ahsp_id = ? AND rc.project_id = ?
    ", [$ahspRap['rab_ahsp_id'], $projectId]);
    
    if (empty($rapItems)) return;
    
    $masterDetails = dbGetAll("
        SELECT d.*, pir.item_code
        FROM project_ahsp_details_rap d
        JOIN project_items_rap pir ON d.item_id = pir.id
        WHERE d.ahsp_id = ?
    ", [$ahspRapId]);
    
    foreach ($rapItems as $rapItem) {
        $rapItemId = $rapItem['rap_item_id'];
        
        foreach ($masterDetails as $masterDetail) {
            $rabItem = dbGetRow("
                SELECT id FROM project_items 
                WHERE project_id = ? AND CONVERT(item_code USING utf8mb4) = CONVERT(? USING utf8mb4)
            ", [$projectId, $masterDetail['item_code']]);
            
            if (!$rabItem) continue;
            
            $existingDetail = dbGetRow("
                SELECT id FROM rap_ahsp_details 
                WHERE rap_item_id = ? AND item_id = ?
            ", [$rapItemId, $rabItem['id']]);
            
            if ($existingDetail) {
                dbExecute("
                    UPDATE rap_ahsp_details 
                    SET coefficient = ?, unit_price = ?, updated_at = NOW()
                    WHERE id = ?
                ", [$masterDetail['coefficient'], $masterDetail['unit_price'], $existingDetail['id']]);
            }
        }
        
        $newUnitPrice = dbGetRow("
            SELECT COALESCE(SUM(coefficient * unit_price), 0) as total 
            FROM rap_ahsp_details 
            WHERE rap_item_id = ?
        ", [$rapItemId])['total'] ?? 0;
        
        dbExecute("UPDATE rap_items SET unit_price = ? WHERE id = ?", [$newUnitPrice, $rapItemId]);
    }
}

/**
 * Sync RAP Table (rap_ahsp_details) to Master Data AHSP RAP
 * Called when editing coefficient/price in ahsp_rap.php
 * 
 * @param int $rapItemId RAP Item ID in rap_items table
 * @param int $projectId
 */
function syncRapTableToMasterAhspRap($rapItemId, $projectId) {
    $rapItem = dbGetRow("
        SELECT ri.*, rs.ahsp_id as rab_ahsp_id
        FROM rap_items ri
        JOIN rab_subcategories rs ON ri.subcategory_id = rs.id
        WHERE ri.id = ?
    ", [$rapItemId]);
    
    if (!$rapItem || !$rapItem['rab_ahsp_id']) return;
    
    $ahspRap = dbGetRow("
        SELECT id FROM project_ahsp_rap 
        WHERE rab_ahsp_id = ? AND project_id = ?
    ", [$rapItem['rab_ahsp_id'], $projectId]);
    
    if (!$ahspRap) return;
    
    $ahspRapId = $ahspRap['id'];
    
    $rapDetails = dbGetAll("
        SELECT rd.*, pi.item_code
        FROM rap_ahsp_details rd
        JOIN project_items pi ON rd.item_id = pi.id
        WHERE rd.rap_item_id = ?
    ", [$rapItemId]);
    
    foreach ($rapDetails as $rapDetail) {
        $rapMasterItem = dbGetRow("
            SELECT id FROM project_items_rap 
            WHERE project_id = ? AND CONVERT(item_code USING utf8mb4) = CONVERT(? USING utf8mb4)
        ", [$projectId, $rapDetail['item_code']]);
        
        if (!$rapMasterItem) continue;
        
        $existingDetail = dbGetRow("
            SELECT id FROM project_ahsp_details_rap 
            WHERE ahsp_id = ? AND item_id = ?
        ", [$ahspRapId, $rapMasterItem['id']]);
        
        if ($existingDetail) {
            dbExecute("
                UPDATE project_ahsp_details_rap 
                SET coefficient = ?, unit_price = ?
                WHERE id = ?
            ", [$rapDetail['coefficient'], $rapDetail['unit_price'], $existingDetail['id']]);
        }
    }
    
    recalculateRapAhspPrice($ahspRapId);
}

// ============================================
// RAB AHSP HELPER FUNCTIONS 
// (moved here so they are available to all handlers)
// ============================================

/**
 * Recalculate RAB AHSP price from its components
 * @param int $ahspId AHSP ID in project_ahsp table
 */
function recalculateAhspPrice($ahspId) {
    $total = dbGetRow("
        SELECT COALESCE(SUM(d.coefficient * COALESCE(d.unit_price, i.price)), 0) as total
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        WHERE d.ahsp_id = ?
    ", [$ahspId])['total'];
    
    dbExecute("UPDATE project_ahsp SET unit_price = ? WHERE id = ?", [$total, $ahspId]);
    
    // Sync to RAB subcategories that use this AHSP
    syncAhspToRab($ahspId);
}

/**
 * Sync AHSP changes to RAB subcategories
 * @param int $ahspId
 */
function syncAhspToRab($ahspId) {
    $ahsp = dbGetRow("SELECT work_name, unit, unit_price FROM project_ahsp WHERE id = ?", [$ahspId]);
    if (!$ahsp) return;
    
    dbExecute("
        UPDATE rab_subcategories 
        SET name = ?, unit = ?, unit_price = ?
        WHERE ahsp_id = ?
    ", [$ahsp['work_name'], $ahsp['unit'], $ahsp['unit_price'], $ahspId]);
    
    dbExecute("
        UPDATE rap_items rap
        JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
        SET rap.unit_price = rs.unit_price
        WHERE rs.ahsp_id = ? AND rap.is_locked = 0
    ", [$ahspId]);
}

/**
 * Sync Item changes to all AHSP that use this item
 * @param int $itemId
 * @param int $projectId
 */
function syncItemToAhsp($itemId, $projectId) {
    // Clear any stale override unit_price in details so it dynamically uses new master price
    dbExecute("UPDATE project_ahsp_details SET unit_price = NULL WHERE item_id = ?", [$itemId]);
    
    $affectedAhsp = dbGetAll("
        SELECT DISTINCT d.ahsp_id 
        FROM project_ahsp_details d
        JOIN project_ahsp pa ON d.ahsp_id = pa.id
        WHERE d.item_id = ? AND pa.project_id = ?
    ", [$itemId, $projectId]);
    
    foreach ($affectedAhsp as $row) {
        recalculateAhspPrice($row['ahsp_id']);
    }
}

// ============================================
// AHSP RAB ↔ RAP BI-DIRECTIONAL SYNC FUNCTIONS
// ============================================

/**
 * Sync: When adding a component to RAB AHSP, mirror it to RAP AHSP
 * @param int $ahspId RAB AHSP ID
 * @param int $itemId RAB Item ID
 * @param float $coefficient
 * @param int $projectId
 */
function syncAddComponentRabToRap($ahspId, $itemId, $coefficient, $projectId) {
    // Find matching RAP AHSP by ahsp_code
    $rabAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp WHERE id = ?", [$ahspId]);
    if (!$rabAhsp) return;
    
    $rapAhsp = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rabAhsp['ahsp_code']]);
    if (!$rapAhsp) return;
    
    // Find matching RAP item by item_code
    $rabItem = dbGetRow("SELECT item_code FROM project_items WHERE id = ?", [$itemId]);
    if (!$rabItem) return;
    
    $rapItem = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", 
        [$projectId, $rabItem['item_code']]);
    if (!$rapItem) return;
    
    // Check if already exists
    $existing = dbGetRow("SELECT id FROM project_ahsp_details_rap WHERE ahsp_id = ? AND item_id = ?", 
        [$rapAhsp['id'], $rapItem['id']]);
    if (!$existing) {
        dbInsert("INSERT INTO project_ahsp_details_rap (ahsp_id, item_id, coefficient) VALUES (?, ?, ?)",
            [$rapAhsp['id'], $rapItem['id'], $coefficient]);
        recalculateRapAhspPrice($rapAhsp['id']);
    }
}

/**
 * Sync: When adding a component to RAP AHSP, mirror it to RAB AHSP
 * @param int $ahspRapId RAP AHSP ID
 * @param int $rapItemId RAP Item ID
 * @param float $coefficient
 * @param int $projectId
 */
function syncAddComponentRapToRab($ahspRapId, $rapItemId, $coefficient, $projectId) {
    // Find matching RAB AHSP by ahsp_code
    $rapAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp_rap WHERE id = ?", [$ahspRapId]);
    if (!$rapAhsp) return;
    
    $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rapAhsp['ahsp_code']]);
    if (!$rabAhsp) return;
    
    // Find matching RAB item by item_code
    $rapItem = dbGetRow("SELECT item_code FROM project_items_rap WHERE id = ?", [$rapItemId]);
    if (!$rapItem) return;
    
    $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", 
        [$projectId, $rapItem['item_code']]);
    if (!$rabItem) return;
    
    // Check if already exists
    $existing = dbGetRow("SELECT id FROM project_ahsp_details WHERE ahsp_id = ? AND item_id = ?", 
        [$rabAhsp['id'], $rabItem['id']]);
    if (!$existing) {
        dbInsert("INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient) VALUES (?, ?, ?)",
            [$rabAhsp['id'], $rabItem['id'], $coefficient]);
        recalculateAhspPrice($rabAhsp['id']);
    }
}

/**
 * Sync: When deleting a component from RAB AHSP, mirror deletion to RAP AHSP
 * @param int $detailId RAB detail ID (to find which item)
 * @param int $ahspId RAB AHSP ID
 * @param int $projectId
 */
function syncDeleteComponentRabToRap($detailId, $ahspId, $projectId) {
    // Get the detail info before it's deleted
    $detail = dbGetRow("SELECT d.item_id, i.item_code FROM project_ahsp_details d JOIN project_items i ON d.item_id = i.id WHERE d.id = ?", [$detailId]);
    if (!$detail) return;
    
    $rabAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp WHERE id = ?", [$ahspId]);
    if (!$rabAhsp) return;
    
    $rapAhsp = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rabAhsp['ahsp_code']]);
    if (!$rapAhsp) return;
    
    $rapItem = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", 
        [$projectId, $detail['item_code']]);
    if (!$rapItem) return;
    
    dbExecute("DELETE FROM project_ahsp_details_rap WHERE ahsp_id = ? AND item_id = ?", 
        [$rapAhsp['id'], $rapItem['id']]);
    recalculateRapAhspPrice($rapAhsp['id']);
}

/**
 * Sync: When deleting a component from RAP AHSP, mirror deletion to RAB AHSP
 * @param int $detailId RAP detail ID
 * @param int $ahspRapId RAP AHSP ID
 * @param int $projectId
 */
function syncDeleteComponentRapToRab($detailId, $ahspRapId, $projectId) {
    // Get the detail info before it's deleted
    $detail = dbGetRow("SELECT d.item_id, i.item_code FROM project_ahsp_details_rap d JOIN project_items_rap i ON d.item_id = i.id WHERE d.id = ?", [$detailId]);
    if (!$detail) return;
    
    $rapAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp_rap WHERE id = ?", [$ahspRapId]);
    if (!$rapAhsp) return;
    
    $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rapAhsp['ahsp_code']]);
    if (!$rabAhsp) return;
    
    $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", 
        [$projectId, $detail['item_code']]);
    if (!$rabItem) return;
    
    dbExecute("DELETE FROM project_ahsp_details WHERE ahsp_id = ? AND item_id = ?", 
        [$rabAhsp['id'], $rabItem['id']]);
    recalculateAhspPrice($rabAhsp['id']);
}

/**
 * Sync: When editing coefficient in RAB AHSP detail, mirror to RAP
 * @param int $ahspId RAB AHSP ID
 * @param int $itemId RAB Item ID
 * @param float $coefficient New coefficient
 * @param int $projectId
 */
function syncCoefficientRabToRap($ahspId, $itemId, $coefficient, $projectId) {
    $rabAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp WHERE id = ?", [$ahspId]);
    if (!$rabAhsp) return;
    
    $rapAhsp = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rabAhsp['ahsp_code']]);
    if (!$rapAhsp) return;
    
    $rabItem = dbGetRow("SELECT item_code FROM project_items WHERE id = ?", [$itemId]);
    if (!$rabItem) return;
    
    $rapItem = dbGetRow("SELECT id FROM project_items_rap WHERE project_id = ? AND item_code = ?", 
        [$projectId, $rabItem['item_code']]);
    if (!$rapItem) return;
    
    dbExecute("UPDATE project_ahsp_details_rap SET coefficient = ? WHERE ahsp_id = ? AND item_id = ?", 
        [$coefficient, $rapAhsp['id'], $rapItem['id']]);
    recalculateRapAhspPrice($rapAhsp['id']);
}

/**
 * Sync: When editing coefficient in RAP AHSP detail, mirror to RAB
 * @param int $ahspRapId RAP AHSP ID
 * @param int $rapItemId RAP Item ID
 * @param float $coefficient New coefficient
 * @param int $projectId
 */
function syncCoefficientRapToRab($ahspRapId, $rapItemId, $coefficient, $projectId) {
    $rapAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp_rap WHERE id = ?", [$ahspRapId]);
    if (!$rapAhsp) return;
    
    $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rapAhsp['ahsp_code']]);
    if (!$rabAhsp) return;
    
    $rapItem = dbGetRow("SELECT item_code FROM project_items_rap WHERE id = ?", [$rapItemId]);
    if (!$rapItem) return;
    
    $rabItem = dbGetRow("SELECT id FROM project_items WHERE project_id = ? AND item_code = ?", 
        [$projectId, $rapItem['item_code']]);
    if (!$rabItem) return;
    
    dbExecute("UPDATE project_ahsp_details SET coefficient = ? WHERE ahsp_id = ? AND item_id = ?", 
        [$coefficient, $rabAhsp['id'], $rabItem['id']]);
    recalculateAhspPrice($rabAhsp['id']);
}

/**
 * Sync: When editing RAB AHSP (name/unit), mirror to RAP
 * @param int $ahspId RAB AHSP ID
 * @param string $ahspCode
 * @param string $workName
 * @param string $unit
 * @param int $projectId
 */
function syncEditAhspRabToRap($ahspId, $ahspCode, $workName, $unit, $projectId) {
    // Find old ahsp_code to locate RAP counterpart
    $oldAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp WHERE id = ?", [$ahspId]);
    $oldCode = $oldAhsp ? $oldAhsp['ahsp_code'] : $ahspCode;
    
    dbExecute("UPDATE project_ahsp_rap SET ahsp_code = ?, work_name = ?, unit = ? WHERE project_id = ? AND ahsp_code = ?",
        [$ahspCode, $workName, $unit, $projectId, $oldCode]);
}

/**
 * Sync: When editing RAP AHSP (name/unit), mirror to RAB
 * @param int $ahspRapId RAP AHSP ID
 * @param string $ahspCode
 * @param string $workName
 * @param string $unit
 * @param int $projectId
 */
function syncEditAhspRapToRab($ahspRapId, $ahspCode, $workName, $unit, $projectId) {
    $oldAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp_rap WHERE id = ?", [$ahspRapId]);
    $oldCode = $oldAhsp ? $oldAhsp['ahsp_code'] : $ahspCode;
    
    $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $oldCode]);
    if (!$rabAhsp) return;
    
    dbExecute("UPDATE project_ahsp SET ahsp_code = ?, work_name = ?, unit = ? WHERE id = ?",
        [$ahspCode, $workName, $unit, $rabAhsp['id']]);
    
    // Also sync to RAB subcategories
    syncAhspToRab($rabAhsp['id']);
}

/**
 * Sync: When deleting RAB AHSP, also delete matching RAP AHSP
 * @param int $ahspId RAB AHSP ID
 * @param int $projectId
 */
function syncDeleteAhspRabToRap($ahspId, $projectId) {
    $rabAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp WHERE id = ?", [$ahspId]);
    if (!$rabAhsp) return;
    
    $rapAhsp = dbGetRow("SELECT id FROM project_ahsp_rap WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rabAhsp['ahsp_code']]);
    if (!$rapAhsp) return;
    
    // Delete RAP AHSP details first
    dbExecute("DELETE FROM project_ahsp_details_rap WHERE ahsp_id = ?", [$rapAhsp['id']]);
    // Delete RAP AHSP
    dbExecute("DELETE FROM project_ahsp_rap WHERE id = ?", [$rapAhsp['id']]);
}

/**
 * Sync: When deleting RAP AHSP, also delete matching RAB AHSP
 * @param int $ahspRapId RAP AHSP ID
 * @param int $projectId
 */
function syncDeleteAhspRapToRab($ahspRapId, $projectId) {
    $rapAhsp = dbGetRow("SELECT ahsp_code FROM project_ahsp_rap WHERE id = ?", [$ahspRapId]);
    if (!$rapAhsp) return;
    
    $rabAhsp = dbGetRow("SELECT id FROM project_ahsp WHERE project_id = ? AND ahsp_code = ?", 
        [$projectId, $rapAhsp['ahsp_code']]);
    if (!$rabAhsp) return;
    
    $rabAhspId = $rabAhsp['id'];
    
    // Delete in correct order due to foreign key constraints
    // 1. Delete rap_ahsp_details for subcategories using this AHSP
    dbExecute("
        DELETE rad FROM rap_ahsp_details rad
        INNER JOIN rap_items rap ON rad.rap_item_id = rap.id
        INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
        WHERE rs.ahsp_id = ?
    ", [$rabAhspId]);
    
    // 2. Delete rap_items for subcategories using this AHSP
    dbExecute("
        DELETE rap FROM rap_items rap
        INNER JOIN rab_subcategories rs ON rap.subcategory_id = rs.id
        WHERE rs.ahsp_id = ?
    ", [$rabAhspId]);
    
    // 3. Delete rab_subcategories
    dbExecute("DELETE FROM rab_subcategories WHERE ahsp_id = ?", [$rabAhspId]);
    
    // 4. Delete RAB AHSP details
    dbExecute("DELETE FROM project_ahsp_details WHERE ahsp_id = ?", [$rabAhspId]);
    
    // 5. Delete RAB AHSP
    dbExecute("DELETE FROM project_ahsp WHERE id = ?", [$rabAhspId]);
}

/**
 * Auto Migration: Ensure rab_head_subs table and head_sub_id column exist
 */
function ensureRabHeadSubsTableExists() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    ensureProfitPercentageColumnExists();
    ensureOverheadApplyColumnsExist();

    try {
        dbExecute("
            CREATE TABLE IF NOT EXISTS `rab_head_subs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `project_id` int(11) NOT NULL,
              `code` varchar(20) DEFAULT NULL,
              `name` varchar(200) NOT NULL,
              `sort_order` int(11) DEFAULT 0,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_project` (`project_id`),
              CONSTRAINT `rab_head_subs_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $colCheck = dbGetRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'rab_categories'
              AND COLUMN_NAME = 'head_sub_id'
        ");

        if (empty($colCheck['cnt'])) {
            dbExecute("
                ALTER TABLE `rab_categories` 
                ADD COLUMN `head_sub_id` int(11) DEFAULT NULL AFTER `project_id`,
                ADD CONSTRAINT `fk_rab_categories_head_sub` FOREIGN KEY (`head_sub_id`) REFERENCES `rab_head_subs` (`id`) ON DELETE SET NULL
            ");
        }

        $snapCatColCheck = dbGetRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'rab_snapshot_categories'
              AND COLUMN_NAME = 'head_sub_id'
        ");

        if (empty($snapCatColCheck['cnt'])) {
            dbExecute("
                ALTER TABLE `rab_snapshot_categories` 
                ADD COLUMN `head_sub_id` int(11) DEFAULT NULL AFTER `original_category_id`
            ");
        }
    } catch (Exception $e) {
        // Ignore if already exists or schema error
    }
}

/**
 * Auto Migration: Ensure profit_percentage column exists in projects and rab_snapshots
 */
function ensureProfitPercentageColumnExists() {
    static $profitChecked = false;
    if ($profitChecked) return;
    $profitChecked = true;

    try {
        $colCheck = dbGetRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'projects'
              AND COLUMN_NAME = 'profit_percentage'
        ");

        if (empty($colCheck['cnt'])) {
            dbExecute("
                ALTER TABLE `projects` 
                ADD COLUMN `profit_percentage` DECIMAL(5,2) DEFAULT 0.00 AFTER `overhead_percentage`
            ");
        }

        // Also ensure rab_snapshots has profit_percentage
        $snapCheck = dbGetRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'rab_snapshots'
        ");
        if (!empty($snapCheck['cnt'])) {
            $snapColCheck = dbGetRow("
                SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'rab_snapshots'
                  AND COLUMN_NAME = 'profit_percentage'
            ");
            if (empty($snapColCheck['cnt'])) {
                dbExecute("
                    ALTER TABLE `rab_snapshots` 
                    ADD COLUMN `profit_percentage` DECIMAL(5,2) DEFAULT 0.00 AFTER `overhead_percentage`
                ");
            }
        }
    } catch (Exception $e) {
        // Ignore if already exists or schema error
    }
}

/**
 * Auto Migration: Ensure overhead_apply_ahsp, overhead_apply_rab, overhead_apply_rap exist in projects and rab_snapshots
 */
function ensureOverheadApplyColumnsExist() {
    static $columnsChecked = false;
    if ($columnsChecked) return;
    $columnsChecked = true;

    ensureProfitPercentageColumnExists();

    try {
        $cols = ['overhead_apply_ahsp', 'overhead_apply_rab', 'overhead_apply_rap'];
        foreach ($cols as $col) {
            $colCheck = dbGetRow("
                SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'projects'
                  AND COLUMN_NAME = ?
            ", [$col]);

            if (empty($colCheck['cnt'])) {
                dbExecute("
                    ALTER TABLE `projects` 
                    ADD COLUMN `{$col}` TINYINT(1) NOT NULL DEFAULT 1
                ");
            }
        }

        // Also ensure rab_snapshots has these columns if rab_snapshots exists
        $snapCheck = dbGetRow("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'rab_snapshots'
        ");
        if (!empty($snapCheck['cnt'])) {
            foreach ($cols as $col) {
                $snapColCheck = dbGetRow("
                    SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'rab_snapshots'
                      AND COLUMN_NAME = ?
                ", [$col]);
                if (empty($snapColCheck['cnt'])) {
                    dbExecute("
                        ALTER TABLE `rab_snapshots` 
                        ADD COLUMN `{$col}` TINYINT(1) NOT NULL DEFAULT 1
                    ");
                }
            }
        }
    } catch (Exception $e) {
        // Ignore if already exists or schema error
    }
}

/**
 * Check if Overhead & Profit is enabled for a specific scope ('ahsp', 'rab', 'rap')
 *
 * @param array|null $project
 * @param string $scope 'ahsp', 'rab', or 'rap'
 * @return bool
 */
function isProjectOverheadEnabled($project, $scope) {
    if (!$project) return true;
    $scopeKey = 'overhead_apply_' . strtolower(trim($scope));
    if (!array_key_exists($scopeKey, $project) || $project[$scopeKey] === null) {
        return true; // Default ON for backward compatibility
    }
    return intval($project[$scopeKey]) === 1;
}

/**
 * Get combined Overhead + Profit percentage for a project
 * Optionally specify scope: 'ahsp', 'rab', 'rap', or null for total configured percentage
 *
 * @param array|null $project
 * @param string|null $scope 'ahsp', 'rab', 'rap', or null
 * @return float
 */
function getProjectOverheadProfitPct($project, $scope = null) {
    if (!$project) return 10.0;
    
    $overhead = isset($project['overhead_percentage']) ? floatval($project['overhead_percentage']) : 10.0;
    $profit = isset($project['profit_percentage']) ? floatval($project['profit_percentage']) : 0.0;
    $total = $overhead + $profit;

    if ($scope !== null) {
        if (!isProjectOverheadEnabled($project, $scope)) {
            return 0.0;
        }
    }

    return $total;
}

/**
 * Get formatted label for Overhead & Profit
 * e.g. "Overhead & Profit 15% (Overhead 5% + Profit 10%)" or "Overhead & Profit 10%"
 * Or "Overhead & Profit: Non-aktif (0%)" if scope is disabled.
 *
 * @param array|null $project
 * @param string|null $scope 'ahsp', 'rab', 'rap', or null
 * @return string
 */
function formatOverheadProfitLabel($project, $scope = null) {
    if (!$project) return "Overhead & Profit 10%";
    
    if ($scope !== null && !isProjectOverheadEnabled($project, $scope)) {
        return "Overhead & Profit: Non-aktif (0%)";
    }

    $overhead = isset($project['overhead_percentage']) ? floatval($project['overhead_percentage']) : 10.0;
    $profit = isset($project['profit_percentage']) ? floatval($project['profit_percentage']) : 0.0;
    $total = $overhead + $profit;
    if ($profit > 0 && $overhead > 0) {
        return "Overhead & Profit " . formatNumber($total, 0) . "% (" . formatNumber($overhead, 0) . "% + " . formatNumber($profit, 0) . "%)";
    }
    return "Overhead & Profit " . formatNumber($total, 0) . "%";
}

/**
 * Calculate real-time RAB, RAP, and Actual statistics for a single project.
 * Uses exact dynamic AHSP component prices, overhead percentage, PPN, and actualization adjustments.
 * 
 * @param int $projectId
 * @return array|null
 */
function calculateProjectRealtimeStats($projectId) {
    $project = dbGetRow("SELECT * FROM projects WHERE id = ?", [$projectId]);
    if (!$project) return null;

    $rabOverheadPct = getProjectOverheadProfitPct($project, 'rab');
    $rapOverheadPct = getProjectOverheadProfitPct($project, 'rap');
    $ppnPct = floatval($project['ppn_percentage'] ?? 11);

    // Pre-calculate RAB AHSP component totals
    $ahspPrices = [];
    $ahspRows = dbGetAll("
        SELECT d.ahsp_id, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details d 
        JOIN project_items i ON d.item_id = i.id 
        JOIN project_ahsp pa ON d.ahsp_id = pa.id
        WHERE pa.project_id = ?
        GROUP BY d.ahsp_id
    ", [$projectId]);
    foreach ($ahspRows as $r) {
        $ahspPrices[$r['ahsp_id']] = floatval($r['total']);
    }

    // Pre-calculate RAP AHSP component totals
    $ahspRapPrices = [];
    $ahspRapRows = dbGetAll("
        SELECT pa.ahsp_code, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total 
        FROM project_ahsp_details_rap d 
        JOIN project_items_rap i ON d.item_id = i.id 
        JOIN project_ahsp_rap pa ON d.ahsp_id = pa.id
        WHERE pa.project_id = ?
        GROUP BY pa.ahsp_code
    ", [$projectId]);
    foreach ($ahspRapRows as $r) {
        $ahspRapPrices[$r['ahsp_code']] = floatval($r['total']);
    }

    // Pre-compute actualization adjustments per subcategory
    $actualizationAdjustments = [];
    $actualizedRequests = dbGetAll("
        SELECT r.id as request_id, 
               GREATEST(ra.remaining_upah - ra.consumed_upah, 0) + 
               GREATEST(ra.remaining_material - ra.consumed_material, 0) + 
               GREATEST(ra.remaining_alat - ra.consumed_alat, 0) as total_remaining
        FROM requests r
        JOIN request_actuals ra ON ra.request_id = r.id
        WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
        HAVING total_remaining > 0
    ", [$projectId]);

    foreach ($actualizedRequests as $ar) {
        $totalRemaining = floatval($ar['total_remaining']);
        if ($totalRemaining <= 0) continue;

        $reqItems = dbGetAll("
            SELECT reqi.subcategory_id, SUM(reqi.total_price) as subcat_total
            FROM request_items reqi
            WHERE reqi.request_id = ?
            GROUP BY reqi.subcategory_id
        ", [$ar['request_id']]);

        $reqTotal = 0;
        foreach ($reqItems as $ri) {
            $reqTotal += floatval($ri['subcat_total']);
        }

        if ($reqTotal > 0) {
            foreach ($reqItems as $ri) {
                $subcatId = intval($ri['subcategory_id']);
                $proportion = floatval($ri['subcat_total']) / $reqTotal;
                $deduction = $totalRemaining * $proportion;
                $actualizationAdjustments[$subcatId] = ($actualizationAdjustments[$subcatId] ?? 0) + $deduction;
            }
        }
    }

    // Fetch categories and subcategories
    $categories = dbGetAll("
        SELECT rc.id, rc.code, rc.name, rc.sort_order
        FROM rab_categories rc
        WHERE rc.project_id = ?
        ORDER BY rc.sort_order, rc.code
    ", [$projectId]);

    $categoryStats = [];
    $subtotalRab = 0;
    $totalRap = 0;
    $totalActual = 0;

    foreach ($categories as $cat) {
        $subcats = dbGetAll("
            SELECT rs.id, rs.code, rs.name, rs.unit, rs.volume as rab_volume, rs.unit_price as rab_unit_price, rs.ahsp_id,
                   rap.volume as rap_volume, rap.unit_price as rap_unit_price,
                   pa.ahsp_code
            FROM rab_subcategories rs
            LEFT JOIN rap_items rap ON rs.id = rap.subcategory_id
            LEFT JOIN project_ahsp pa ON rs.ahsp_id = pa.id
            WHERE rs.category_id = ?
            ORDER BY rs.sort_order, rs.code
        ", [$cat['id']]);

        $catRab = 0;
        $catRap = 0;
        $catActual = 0;

        foreach ($subcats as $sub) {
            // RAB calculation
            $rabBasePrice = isset($ahspPrices[$sub['ahsp_id']]) ? $ahspPrices[$sub['ahsp_id']] : floatval($sub['rab_unit_price']);
            $rabUnitPrice = $rabBasePrice * (1 + ($rabOverheadPct / 100));
            $subRab = floatval($sub['rab_volume']) * $rabUnitPrice;
            $catRab += $subRab;

            // RAP calculation
            $rapVol = (isset($sub['rap_volume']) && $sub['rap_volume'] !== null) ? floatval($sub['rap_volume']) : floatval($sub['rab_volume']);
            $ahspCode = $sub['ahsp_code'] ?? null;
            $rapBasePrice = 0;
            if ($ahspCode && isset($ahspRapPrices[$ahspCode])) {
                $rapBasePrice = $ahspRapPrices[$ahspCode];
            }
            if ($rapBasePrice <= 0) {
                $rapBasePrice = (isset($sub['rap_unit_price']) && floatval($sub['rap_unit_price']) > 0) ? floatval($sub['rap_unit_price']) : floatval($sub['rab_unit_price']);
            }
            $rapUnitPrice = $rapBasePrice * (1 + ($rapOverheadPct / 100));
            $subRap = $rapVol * $rapUnitPrice;
            $catRap += $subRap;

            // Actual calculation
            $actRow = dbGetRow("
                SELECT COALESCE(SUM(reqi.total_price), 0) as total
                FROM request_items reqi
                JOIN requests req ON reqi.request_id = req.id
                WHERE reqi.subcategory_id = ? AND req.status = 'approved' AND req.project_id = ?
            ", [$sub['id'], $projectId]);
            $subAct = floatval($actRow['total'] ?? 0);
            $adj = $actualizationAdjustments[$sub['id']] ?? 0;
            $subAct -= $adj;
            if ($subAct < 0) $subAct = 0;
            $catActual += $subAct;
        }

        $categoryStats[] = [
            'id' => $cat['id'],
            'code' => $cat['code'],
            'name' => $cat['name'],
            'rab_total' => $catRab,
            'rap_total' => $catRap,
            'actual_total' => $catActual
        ];

        $subtotalRab += $catRab;
        $totalRap += $catRap;
        $totalActual += $catActual;
    }

    $ppnAmount = $subtotalRab * ($ppnPct / 100);
    $totalRabWithPpn = $subtotalRab + $ppnAmount;
    $totalRabRounded = ceil($totalRabWithPpn / 10) * 10;

    return [
        'project' => $project,
        'name' => $project['name'],
        'id' => $project['id'],
        'subtotal_rab' => $subtotalRab,
        'ppn_amount' => $ppnAmount,
        'total_rab' => $totalRabRounded,
        'total_rab_raw' => $totalRabWithPpn,
        'total_rap' => $totalRap,
        'total_actual' => $totalActual,
        'category_stats' => $categoryStats
    ];
}

/**
 * Batch-load ALL AHSP component breakdowns for a project (RAB Master Data).
 * Returns a lookup map: [ahsp_id => ['upah' => float, 'material' => float, 'alat' => float, 'total' => float]]
 * Replaces per-subcategory getAhspComponentBreakdown() calls with a single query.
 *
 * @param int $projectId
 * @return array
 */
function batchGetAhspComponentBreakdowns($projectId) {
    $rows = dbGetAll("
        SELECT d.ahsp_id, i.category, SUM(d.coefficient * COALESCE(d.unit_price, i.price)) as total
        FROM project_ahsp_details d
        JOIN project_items i ON d.item_id = i.id
        JOIN project_ahsp pa ON d.ahsp_id = pa.id
        WHERE pa.project_id = ?
        GROUP BY d.ahsp_id, i.category
    ", [$projectId]);

    $map = [];
    foreach ($rows as $row) {
        $ahspId = $row['ahsp_id'];
        if (!isset($map[$ahspId])) {
            $map[$ahspId] = ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0, 'total' => 0.0];
        }
        $cat = $row['category'] ?? '';
        if (isset($map[$ahspId][$cat])) {
            $map[$ahspId][$cat] = floatval($row['total']);
        }
        $map[$ahspId]['total'] += floatval($row['total']);
    }

    return $map;
}

/**
 * Batch-load ALL RAP AHSP component breakdowns for a project.
 * Returns a lookup map: [ahsp_code => ['upah' => float, 'material' => float, 'alat' => float]]
 * Replaces per-subcategory getRapAhspComponentBreakdown() calls with a single query.
 *
 * @param int $projectId
 * @return array
 */
function batchGetRapAhspComponentBreakdowns($projectId) {
    $rows = dbGetAll("
        SELECT par.ahsp_code, pir.category, SUM(d.coefficient * COALESCE(d.unit_price, pir.price)) as total
        FROM project_ahsp_details_rap d
        JOIN project_items_rap pir ON d.item_id = pir.id
        JOIN project_ahsp_rap par ON d.ahsp_id = par.id
        WHERE par.project_id = ?
        GROUP BY par.ahsp_code, pir.category
    ", [$projectId]);

    $map = [];
    foreach ($rows as $row) {
        $code = $row['ahsp_code'];
        if (!isset($map[$code])) {
            $map[$code] = ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
        }
        $cat = $row['category'] ?? '';
        if (isset($map[$code][$cat])) {
            $map[$code][$cat] = floatval($row['total']);
        }
    }

    return $map;
}

/**
 * Batch-load actual spending per subcategory for a project.
 * Returns a lookup map: [subcategory_id => float total]
 *
 * @param int $projectId
 * @return array
 */
function batchGetActualSpendingBySubcategory($projectId) {
    $rows = dbGetAll("
        SELECT reqi.subcategory_id, COALESCE(SUM(reqi.total_price), 0) as total
        FROM request_items reqi
        JOIN requests req ON reqi.request_id = req.id
        WHERE req.project_id = ? AND req.status = 'approved'
        GROUP BY reqi.subcategory_id
    ", [$projectId]);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['subcategory_id']] = floatval($row['total']);
    }
    return $map;
}

/**
 * Batch-load actual spending breakdown by component (upah/material/alat) per subcategory.
 * Returns: [subcategory_id => ['upah' => float, 'material' => float, 'alat' => float]]
 *
 * @param int $projectId
 * @return array
 */
function batchGetActualBreakdownBySubcategory($projectId) {
    $rows = dbGetAll("
        SELECT reqi.subcategory_id, 
               pi.category as item_category,
               COALESCE(SUM(reqi.unit_price * reqi.coefficient), 0) as category_total
        FROM request_items reqi
        JOIN requests req ON reqi.request_id = req.id
        JOIN project_items pi ON pi.item_code = reqi.item_code AND pi.project_id = req.project_id
        WHERE req.project_id = ? AND req.status = 'approved'
        GROUP BY reqi.subcategory_id, pi.category
    ", [$projectId]);

    $map = [];
    foreach ($rows as $row) {
        $subId = $row['subcategory_id'];
        if (!isset($map[$subId])) {
            $map[$subId] = ['upah' => 0.0, 'material' => 0.0, 'alat' => 0.0];
        }
        $cat = $row['item_category'] ?? '';
        if (isset($map[$subId][$cat])) {
            $map[$subId][$cat] = floatval($row['category_total']);
        }
    }
    return $map;
}

/**
 * Batch-load actualization adjustments per subcategory.
 * Returns: [subcategory_id => float deduction_amount]
 *
 * @param int $projectId
 * @return array
 */
function batchGetActualizationAdjustments($projectId) {
    // Get all actualized requests with remaining budget
    $actualizedRequests = dbGetAll("
        SELECT r.id as request_id, 
               GREATEST(ra.remaining_upah - ra.consumed_upah, 0) + 
               GREATEST(ra.remaining_material - ra.consumed_material, 0) + 
               GREATEST(ra.remaining_alat - ra.consumed_alat, 0) as total_remaining
        FROM requests r
        JOIN request_actuals ra ON ra.request_id = r.id
        WHERE r.project_id = ? AND r.status = 'approved' AND r.is_actualized = 1
        HAVING total_remaining > 0
    ", [$projectId]);

    if (empty($actualizedRequests)) {
        return [];
    }

    // Get all request items grouped by request_id and subcategory in ONE query
    $requestIds = array_column($actualizedRequests, 'request_id');
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $allReqItems = dbGetAll("
        SELECT reqi.request_id, reqi.subcategory_id, SUM(reqi.total_price) as subcat_total
        FROM request_items reqi
        WHERE reqi.request_id IN ($placeholders)
        GROUP BY reqi.request_id, reqi.subcategory_id
    ", $requestIds);

    // Build lookup: [request_id => [subcategory_id => total, ...]]
    $reqItemMap = [];
    $reqTotals = [];
    foreach ($allReqItems as $ri) {
        $reqId = $ri['request_id'];
        $reqItemMap[$reqId][$ri['subcategory_id']] = floatval($ri['subcat_total']);
        $reqTotals[$reqId] = ($reqTotals[$reqId] ?? 0) + floatval($ri['subcat_total']);
    }

    // Calculate adjustments
    $adjustments = [];
    foreach ($actualizedRequests as $ar) {
        $totalRemaining = floatval($ar['total_remaining']);
        if ($totalRemaining <= 0) continue;

        $reqId = $ar['request_id'];
        $reqTotal = $reqTotals[$reqId] ?? 0;
        if ($reqTotal <= 0) continue;

        foreach (($reqItemMap[$reqId] ?? []) as $subcatId => $subcatTotal) {
            $proportion = $subcatTotal / $reqTotal;
            $deduction = $totalRemaining * $proportion;
            $adjustments[$subcatId] = ($adjustments[$subcatId] ?? 0) + $deduction;
        }
    }

    return $adjustments;
}

/**
 * Calculate overall real-time RAB, RAP, and Actual statistics across all non-draft projects.
 * 
 * @return array
 */
function getOverallProjectsRealtimeStats() {
    $projects = dbGetAll("SELECT id, status FROM projects WHERE status != 'draft'");
    
    $totalProjects = count($projects);
    $activeProjects = 0;
    $completedProjects = 0;
    $totalRab = 0;
    $totalRap = 0;
    $totalActual = 0;

    foreach ($projects as $p) {
        if ($p['status'] === 'on_progress') $activeProjects++;
        if ($p['status'] === 'completed') $completedProjects++;

        $stats = calculateProjectRealtimeStats($p['id']);
        if ($stats) {
            $totalRab += $stats['total_rab'];
            $totalRap += $stats['total_rap'];
            $totalActual += $stats['total_actual'];
        }
    }

    return [
        'total_projects' => $totalProjects,
        'active_projects' => $activeProjects,
        'completed_projects' => $completedProjects,
        'total_rab' => $totalRab,
        'total_rap' => $totalRap,
        'total_actual' => $totalActual
    ];
}

/**
 * Delete a request and all its associated items, actuals, attachments, and files
 * 
 * @param int $requestId
 * @return bool
 */
function deleteRequest($requestId) {
    $requestId = intval($requestId);
    if ($requestId <= 0) return false;
    
    // 1. Delete request attachments (and physical files)
    $attachments = dbGetAll("SELECT filename FROM request_attachments WHERE request_id = ?", [$requestId]);
    $reqUploadDir = __DIR__ . '/../uploads/requests/';
    foreach ($attachments as $att) {
        if (!empty($att['filename'])) {
            $filepath = $reqUploadDir . $att['filename'];
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }
    }
    dbExecute("DELETE FROM request_attachments WHERE request_id = ?", [$requestId]);
    
    // 2. Delete request actuals and their attachments (and physical files)
    $actuals = dbGetAll("SELECT id FROM request_actuals WHERE request_id = ?", [$requestId]);
    $actUploadDir = __DIR__ . '/../uploads/actuals/';
    foreach ($actuals as $act) {
        $actAttachments = dbGetAll("SELECT filename FROM request_actual_attachments WHERE request_actual_id = ?", [$act['id']]);
        foreach ($actAttachments as $att) {
            if (!empty($att['filename'])) {
                $filepath = $actUploadDir . $att['filename'];
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
        }
        dbExecute("DELETE FROM request_actual_attachments WHERE request_actual_id = ?", [$act['id']]);
    }
    dbExecute("DELETE FROM request_actuals WHERE request_id = ?", [$requestId]);
    
    // 3. Delete request items
    dbExecute("DELETE FROM request_items WHERE request_id = ?", [$requestId]);
    
    // 4. Delete the request record
    $res = dbExecute("DELETE FROM requests WHERE id = ?", [$requestId]);
    return $res > 0;
}

/**
 * Resequence RAB categories and subcategories for a project
 * Ensures sequential sort_orders (1, 2, 3...) and synchronized code letters (A, B, C...)
 * as well as subcategory codes (A.1, A.2, B.1, B.2...)
 * 
 * @param int $projectId
 * @return void
 */
function resequenceRabCategoriesAndSubcategories($projectId) {
    $projectId = intval($projectId);
    if ($projectId <= 0) return;

    // Get all categories in current sort_order, then id
    $categories = dbGetAll("SELECT id, code, sort_order FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$projectId]);
    
    $catIndex = 1;
    foreach ($categories as $cat) {
        $newCatCode = getCategoryCodeFromIndex($catIndex);
        dbExecute("UPDATE rab_categories SET sort_order = ?, code = ? WHERE id = ? AND project_id = ?", 
            [$catIndex, $newCatCode, $cat['id'], $projectId]);
        
        // Resequence subcategories within this category
        $subcats = dbGetAll("SELECT id, code, sort_order FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order ASC, id ASC", [$cat['id']]);
        $subIndex = 1;
        foreach ($subcats as $sub) {
            $newSubCode = $newCatCode . '.' . $subIndex;
            dbExecute("UPDATE rab_subcategories SET sort_order = ?, code = ? WHERE id = ?", 
                [$subIndex, $newSubCode, $sub['id']]);
            $subIndex++;
        }
        
        $catIndex++;
    }
}

/**
 * Check if a project code is available (not already used by another project)
 * 
 * @param string|null $code
 * @param int|null $excludeProjectId
 * @return bool
 */
function isProjectCodeAvailable($code, $excludeProjectId = null) {
    $code = trim($code ?? '');
    if ($code === '') {
        return true;
    }
    $sql = "SELECT id FROM projects WHERE project_code = ?";
    $params = [$code];
    if (!empty($excludeProjectId)) {
        $sql .= " AND id != ?";
        $params[] = intval($excludeProjectId);
    }
    $existing = dbGetRow($sql, $params);
    return empty($existing);
}

/**
 * Generate a unique project name with - Copy / - Copy (N) suffix
 * 
 * @param string $baseName
 * @return string
 */
function generateUniqueProjectName($baseName) {
    $baseName = trim($baseName ?? '');
    if (empty($baseName)) {
        $baseName = 'Proyek Baru';
    }
    
    // Clean existing '- Copy' or '- Copy (N)' from baseName to avoid stacking '- Copy - Copy'
    $cleanedName = preg_replace('/\s*-\s*Copy(?:\s*\(\d+\))?$/i', '', $baseName);
    
    $candidate = $cleanedName . ' - Copy';
    if (!dbGetRow("SELECT id FROM projects WHERE name = ?", [$candidate])) {
        return $candidate;
    }
    
    $counter = 2;
    while ($counter <= 999) {
        $candidate = $cleanedName . ' - Copy (' . $counter . ')';
        if (!dbGetRow("SELECT id FROM projects WHERE name = ?", [$candidate])) {
            return $candidate;
        }
        $counter++;
    }
    
    return $cleanedName . ' - Copy (' . uniqid() . ')';
}

/**
 * Generate a unique project code with -COPY / -COPY-02 suffix or PRJ-YYYYMM-XXX
 * 
 * @param string $baseCode
 * @return string
 */
function generateUniqueProjectCode($baseCode = '') {
    $baseCode = trim($baseCode ?? '');
    
    if (empty($baseCode)) {
        $yearMonth = date('Ym');
        $counter = 1;
        while ($counter <= 999) {
            $candidate = 'PRJ-' . $yearMonth . '-' . sprintf('%03d', $counter);
            if (isProjectCodeAvailable($candidate)) {
                return $candidate;
            }
            $counter++;
        }
        return 'PRJ-' . $yearMonth . '-' . uniqid();
    }
    
    // Clean existing '-COPY' or '-COPY-XX' suffixes
    $cleanedCode = preg_replace('/-COPY(?:-\d+)?$/i', '', $baseCode);
    
    $candidate = $cleanedCode . '-COPY';
    if (isProjectCodeAvailable($candidate)) {
        return $candidate;
    }
    
    $counter = 2;
    while ($counter <= 999) {
        $candidate = $cleanedCode . '-COPY-' . sprintf('%02d', $counter);
        if (isProjectCodeAvailable($candidate)) {
            return $candidate;
        }
        $counter++;
    }
    
    return $cleanedCode . '-COPY-' . uniqid();
}

/**
 * Deep copy an entire project and all project-scoped children and files.
 * 
 * @param int $sourceProjectId
 * @param string $newName
 * @param string|null $newCode
 * @param int $userId
 * @return int $newProjectId
 * @throws RuntimeException|Throwable
 */
function duplicateProject($sourceProjectId, $newName, $newCode, $userId) {
    $sourceProjectId = intval($sourceProjectId);
    $userId = intval($userId);
    
    if ($sourceProjectId <= 0) {
        throw new RuntimeException("ID proyek sumber tidak valid.");
    }
    
    $sourceProject = dbGetRow("SELECT * FROM projects WHERE id = ?", [$sourceProjectId]);
    if (!$sourceProject) {
        throw new RuntimeException("Proyek sumber (ID: $sourceProjectId) tidak ditemukan.");
    }
    
    $newName = trim($newName ?? '');
    if (empty($newName)) {
        throw new RuntimeException("Nama proyek baru tidak boleh kosong.");
    }
    
    $newCode = trim($newCode ?? '');
    if ($newCode !== '' && !isProjectCodeAvailable($newCode)) {
        throw new RuntimeException("Kode proyek '$newCode' sudah digunakan oleh proyek lain.");
    }
    $projectCodeValue = ($newCode === '') ? null : $newCode;
    
    $userId = $userId ?: (function_exists('getCurrentUserId') ? getCurrentUserId() : null);
    $userId = $userId ?: ($sourceProject['created_by'] ?? null);
    
    $createdFiles = [];
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // 1. Dynamic projects table column copy
        $projectCols = dbGetAll("SHOW COLUMNS FROM projects");
        $excludedCols = ['id', 'created_at', 'updated_at'];
        $colsToInsert = [];
        $placeholders = [];
        $values = [];
        
        foreach ($projectCols as $col) {
            $fName = $col['Field'];
            if (in_array($fName, $excludedCols, true) || (!empty($col['Extra']) && strpos($col['Extra'], 'auto_increment') !== false)) {
                continue;
            }
            
            $colsToInsert[] = "`$fName`";
            $placeholders[] = "?";
            
            if ($fName === 'name') {
                $values[] = $newName;
            } elseif ($fName === 'project_code') {
                $values[] = $projectCodeValue;
            } elseif ($fName === 'created_by') {
                $values[] = $userId;
            } elseif ($fName === 'rap_source_id') {
                $values[] = 0; // Temporarily 0, will update after snapshot cloning if applicable
            } else {
                $values[] = $sourceProject[$fName] ?? null;
            }
        }
        
        $sqlInsertProj = "INSERT INTO projects (" . implode(', ', $colsToInsert) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmtProj = $db->prepare($sqlInsertProj);
        $stmtProj->execute($values);
        $newProjectId = intval($db->lastInsertId());
        
        if ($newProjectId <= 0) {
            throw new RuntimeException("Gagal membuat record proyek baru di database.");
        }
        
        // Mapping dictionaries
        $maps = [
            'project_items' => [],
            'project_items_rap' => [],
            'project_ahsp' => [],
            'project_ahsp_details' => [],
            'project_ahsp_rap' => [],
            'project_ahsp_details_rap' => [],
            'rab_head_subs' => [],
            'rab_categories' => [],
            'rab_subcategories' => [],
            'rap_items' => [],
            'rab_snapshots' => [],
            'rab_snapshot_categories' => [],
            'rab_snapshot_subcategories' => [],
            'rab_snapshot_ahsp_details' => [],
            'requests' => [],
            'request_items' => [],
            'request_actuals' => [],
            'weekly_progress' => [],
            'project_images' => [],
            'project_document_folders' => [],
            'project_documents' => []
        ];
        
        // 2. Clone active project assignments
        $assignments = dbGetAll("SELECT * FROM project_assignments WHERE project_id = ? AND is_active = 1", [$sourceProjectId]);
        foreach ($assignments as $a) {
            dbInsert("
                INSERT INTO project_assignments (project_id, user_id, assigned_by, assigned_at, notes, is_active)
                VALUES (?, ?, ?, NOW(), ?, 1)
            ", [$newProjectId, $a['user_id'], $userId, 'Diwariskan dari duplikasi proyek #' . $sourceProjectId]);
        }
        
        // 3. Clone Master Data RAB (project_items)
        $items = dbGetAll("SELECT * FROM project_items WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($items as $item) {
            $newItemId = dbInsert("
                INSERT INTO project_items (project_id, item_code, name, brand, category, unit, price, actual_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ", [$newProjectId, $item['item_code'], $item['name'], $item['brand'], $item['category'], $item['unit'], $item['price'], $item['actual_price']]);
            $maps['project_items'][$item['id']] = intval($newItemId);
        }
        
        // 4. Clone Master Data RAP (project_items_rap)
        $rapItems = dbGetAll("SELECT * FROM project_items_rap WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($rapItems as $item) {
            $newRabItemId = null;
            if ($item['rab_item_id'] !== null) {
                if (!isset($maps['project_items'][$item['rab_item_id']])) {
                    throw new RuntimeException("Missing mapping for project_items_rap rab_item_id: " . $item['rab_item_id']);
                }
                $newRabItemId = $maps['project_items'][$item['rab_item_id']];
            }
            $newRapItemId = dbInsert("
                INSERT INTO project_items_rap (project_id, item_code, name, brand, category, unit, price, actual_price, rab_item_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [$newProjectId, $item['item_code'], $item['name'], $item['brand'], $item['category'], $item['unit'], $item['price'], $item['actual_price'], $newRabItemId]);
            $maps['project_items_rap'][$item['id']] = intval($newRapItemId);
        }
        
        // 5. Clone AHSP Master RAB (project_ahsp & project_ahsp_details)
        $ahsps = dbGetAll("SELECT * FROM project_ahsp WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($ahsps as $ahsp) {
            $newAhspId = dbInsert("
                INSERT INTO project_ahsp (project_id, ahsp_code, work_name, unit, unit_price)
                VALUES (?, ?, ?, ?, ?)
            ", [$newProjectId, $ahsp['ahsp_code'], $ahsp['work_name'], $ahsp['unit'], $ahsp['unit_price']]);
            $maps['project_ahsp'][$ahsp['id']] = intval($newAhspId);
            
            $details = dbGetAll("SELECT * FROM project_ahsp_details WHERE ahsp_id = ? ORDER BY id ASC", [$ahsp['id']]);
            foreach ($details as $d) {
                if (!isset($maps['project_items'][$d['item_id']])) {
                    throw new RuntimeException("Missing mapping for project_ahsp_details item_id: " . $d['item_id']);
                }
                $newItemId = $maps['project_items'][$d['item_id']];
                $newDetailId = dbInsert("
                    INSERT INTO project_ahsp_details (ahsp_id, item_id, coefficient, unit_price)
                    VALUES (?, ?, ?, ?)
                ", [$newAhspId, $newItemId, $d['coefficient'], $d['unit_price']]);
                $maps['project_ahsp_details'][$d['id']] = intval($newDetailId);
            }
        }
        
        // 6. Clone AHSP Master RAP (project_ahsp_rap & project_ahsp_details_rap)
        $rapAhsps = dbGetAll("SELECT * FROM project_ahsp_rap WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($rapAhsps as $ahsp) {
            $newRabAhspId = null;
            if ($ahsp['rab_ahsp_id'] !== null) {
                if (!isset($maps['project_ahsp'][$ahsp['rab_ahsp_id']])) {
                    throw new RuntimeException("Missing mapping for project_ahsp_rap rab_ahsp_id: " . $ahsp['rab_ahsp_id']);
                }
                $newRabAhspId = $maps['project_ahsp'][$ahsp['rab_ahsp_id']];
            }
            $newRapAhspId = dbInsert("
                INSERT INTO project_ahsp_rap (project_id, ahsp_code, work_name, unit, unit_price, rab_ahsp_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$newProjectId, $ahsp['ahsp_code'], $ahsp['work_name'], $ahsp['unit'], $ahsp['unit_price'], $newRabAhspId]);
            $maps['project_ahsp_rap'][$ahsp['id']] = intval($newRapAhspId);
            
            $details = dbGetAll("SELECT * FROM project_ahsp_details_rap WHERE ahsp_id = ? ORDER BY id ASC", [$ahsp['id']]);
            foreach ($details as $d) {
                if (!isset($maps['project_items_rap'][$d['item_id']])) {
                    throw new RuntimeException("Missing mapping for project_ahsp_details_rap item_id: " . $d['item_id']);
                }
                $newItemId = $maps['project_items_rap'][$d['item_id']];
                $newDetailId = dbInsert("
                    INSERT INTO project_ahsp_details_rap (ahsp_id, item_id, coefficient, unit_price)
                    VALUES (?, ?, ?, ?)
                ", [$newRapAhspId, $newItemId, $d['coefficient'], $d['unit_price']]);
                $maps['project_ahsp_details_rap'][$d['id']] = intval($newDetailId);
            }
        }
        
        // 7. Clone RAB Hierarchy (rab_head_subs -> rab_categories -> rab_subcategories)
        $headSubs = dbGetAll("SELECT * FROM rab_head_subs WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$sourceProjectId]);
        foreach ($headSubs as $hs) {
            $newHsId = dbInsert("
                INSERT INTO rab_head_subs (project_id, code, name, sort_order)
                VALUES (?, ?, ?, ?)
            ", [$newProjectId, $hs['code'], $hs['name'], $hs['sort_order']]);
            $maps['rab_head_subs'][$hs['id']] = intval($newHsId);
        }
        
        $categories = dbGetAll("SELECT * FROM rab_categories WHERE project_id = ? ORDER BY sort_order ASC, id ASC", [$sourceProjectId]);
        foreach ($categories as $cat) {
            $newHeadSubId = null;
            if ($cat['head_sub_id'] !== null) {
                if (!isset($maps['rab_head_subs'][$cat['head_sub_id']])) {
                    throw new RuntimeException("Missing mapping for rab_categories head_sub_id: " . $cat['head_sub_id']);
                }
                $newHeadSubId = $maps['rab_head_subs'][$cat['head_sub_id']];
            }
            $newCatId = dbInsert("
                INSERT INTO rab_categories (project_id, head_sub_id, code, name, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ", [$newProjectId, $newHeadSubId, $cat['code'], $cat['name'], $cat['sort_order']]);
            $maps['rab_categories'][$cat['id']] = intval($newCatId);
            
            $subcats = dbGetAll("SELECT * FROM rab_subcategories WHERE category_id = ? ORDER BY sort_order ASC, id ASC", [$cat['id']]);
            foreach ($subcats as $sub) {
                $newAhspId = null;
                if ($sub['ahsp_id'] !== null && intval($sub['ahsp_id']) !== 0) {
                    if (!isset($maps['project_ahsp'][$sub['ahsp_id']])) {
                        throw new RuntimeException("Missing mapping for rab_subcategories ahsp_id: " . $sub['ahsp_id']);
                    }
                    $newAhspId = $maps['project_ahsp'][$sub['ahsp_id']];
                } else {
                    $newAhspId = ($sub['ahsp_id'] === null) ? null : 0;
                }
                $newSubId = dbInsert("
                    INSERT INTO rab_subcategories (category_id, ahsp_id, code, name, unit, volume, unit_price, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ", [$newCatId, $newAhspId, $sub['code'], $sub['name'], $sub['unit'], $sub['volume'], $sub['unit_price'], $sub['sort_order']]);
                $maps['rab_subcategories'][$sub['id']] = intval($newSubId);
            }
        }
        
        // 8. Clone RAP Items & Details (rap_items -> rap_ahsp_details)
        $oldSubcatIds = array_keys($maps['rab_subcategories']);
        $sourceRapItems = [];
        if (!empty($oldSubcatIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($oldSubcatIds), '?'));
            $sourceRapItems = dbGetAll("SELECT * FROM rap_items WHERE subcategory_id IN ($inPlaceholders) ORDER BY id ASC", $oldSubcatIds);
            foreach ($sourceRapItems as $ri) {
                if (!isset($maps['rab_subcategories'][$ri['subcategory_id']])) {
                    throw new RuntimeException("Missing mapping for rap_items subcategory_id: " . $ri['subcategory_id']);
                }
                $newSubcatId = $maps['rab_subcategories'][$ri['subcategory_id']];
                
                $newRapItemRecordId = dbInsert("
                    INSERT INTO rap_items (subcategory_id, volume, unit_price, notes, rab_source_type, rab_snapshot_id, is_locked)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ", [$newSubcatId, $ri['volume'], $ri['unit_price'], $ri['notes'], $ri['rab_source_type'], null, $ri['is_locked']]);
                $maps['rap_items'][$ri['id']] = intval($newRapItemRecordId);
                
                $rapDetails = dbGetAll("SELECT * FROM rap_ahsp_details WHERE rap_item_id = ? ORDER BY id ASC", [$ri['id']]);
                foreach ($rapDetails as $rd) {
                    if (!isset($maps['project_items'][$rd['item_id']])) {
                        throw new RuntimeException("Missing mapping for rap_ahsp_details item_id: " . $rd['item_id']);
                    }
                    $newItemId = $maps['project_items'][$rd['item_id']];
                    dbInsert("
                        INSERT INTO rap_ahsp_details (rap_item_id, item_id, category, coefficient, unit_price)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$newRapItemRecordId, $newItemId, $rd['category'], $rd['coefficient'], $rd['unit_price']]);
                }
            }
        }
        
        // 9. Clone Snapshots Hierarchy (rab_snapshots -> categories -> subcategories -> ahsp_details)
        $snapshots = dbGetAll("SELECT * FROM rab_snapshots WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($snapshots as $snap) {
            $newSnapId = dbInsert("
                INSERT INTO rab_snapshots (
                    project_id, created_by, name, description, overhead_percentage, profit_percentage,
                    ppn_percentage, overhead_apply_ahsp, overhead_apply_rab, overhead_apply_rap
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $newProjectId, $userId, $snap['name'], $snap['description'], $snap['overhead_percentage'], $snap['profit_percentage'],
                $snap['ppn_percentage'], $snap['overhead_apply_ahsp'], $snap['overhead_apply_rab'], $snap['overhead_apply_rap']
            ]);
            $maps['rab_snapshots'][$snap['id']] = intval($newSnapId);
            
            $snapCats = dbGetAll("SELECT * FROM rab_snapshot_categories WHERE snapshot_id = ? ORDER BY sort_order ASC, id ASC", [$snap['id']]);
            foreach ($snapCats as $sc) {
                $newOrigCatId = null;
                if ($sc['original_category_id'] !== null) {
                    if (!isset($maps['rab_categories'][$sc['original_category_id']])) {
                        throw new RuntimeException("Missing mapping for snapshot category original_category_id: " . $sc['original_category_id']);
                    }
                    $newOrigCatId = $maps['rab_categories'][$sc['original_category_id']];
                }
                $newHeadSubId = null;
                if ($sc['head_sub_id'] !== null) {
                    if (!isset($maps['rab_head_subs'][$sc['head_sub_id']])) {
                        throw new RuntimeException("Missing mapping for snapshot category head_sub_id: " . $sc['head_sub_id']);
                    }
                    $newHeadSubId = $maps['rab_head_subs'][$sc['head_sub_id']];
                }
                $newSnapCatId = dbInsert("
                    INSERT INTO rab_snapshot_categories (snapshot_id, original_category_id, head_sub_id, code, name, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ", [$newSnapId, $newOrigCatId, $newHeadSubId, $sc['code'], $sc['name'], $sc['sort_order']]);
                $maps['rab_snapshot_categories'][$sc['id']] = intval($newSnapCatId);
                
                $snapSubs = dbGetAll("SELECT * FROM rab_snapshot_subcategories WHERE category_id = ? ORDER BY sort_order ASC, id ASC", [$sc['id']]);
                foreach ($snapSubs as $ss) {
                    $newOrigSubId = null;
                    if ($ss['original_subcategory_id'] !== null) {
                        if (!isset($maps['rab_subcategories'][$ss['original_subcategory_id']])) {
                            throw new RuntimeException("Missing mapping for snapshot subcategory original_subcategory_id: " . $ss['original_subcategory_id']);
                        }
                        $newOrigSubId = $maps['rab_subcategories'][$ss['original_subcategory_id']];
                    }
                    $newAhspId = null;
                    if ($ss['ahsp_id'] !== null) {
                        if (!isset($maps['project_ahsp'][$ss['ahsp_id']])) {
                            throw new RuntimeException("Missing mapping for snapshot subcategory ahsp_id: " . $ss['ahsp_id']);
                        }
                        $newAhspId = $maps['project_ahsp'][$ss['ahsp_id']];
                    }
                    $newSnapSubId = dbInsert("
                        INSERT INTO rab_snapshot_subcategories (category_id, original_subcategory_id, code, name, unit, volume, unit_price, ahsp_id, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [$newSnapCatId, $newOrigSubId, $ss['code'], $ss['name'], $ss['unit'], $ss['volume'], $ss['unit_price'], $newAhspId, $ss['sort_order']]);
                    $maps['rab_snapshot_subcategories'][$ss['id']] = intval($newSnapSubId);
                    
                    $snapAhspDetails = dbGetAll("SELECT * FROM rab_snapshot_ahsp_details WHERE snapshot_subcategory_id = ? ORDER BY sort_order ASC, id ASC", [$ss['id']]);
                    foreach ($snapAhspDetails as $sad) {
                        if (!isset($maps['project_items'][$sad['item_id']])) {
                            throw new RuntimeException("Missing mapping for snapshot ahsp detail item_id: " . $sad['item_id']);
                        }
                        $newItemId = $maps['project_items'][$sad['item_id']];
                        $newSadId = dbInsert("
                            INSERT INTO rab_snapshot_ahsp_details (snapshot_subcategory_id, item_id, category, coefficient, unit_price, sort_order)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ", [$newSnapSubId, $newItemId, $sad['category'], $sad['coefficient'], $sad['unit_price'], $sad['sort_order']]);
                        $maps['rab_snapshot_ahsp_details'][$sad['id']] = intval($newSadId);
                    }
                }
            }
        }
        
        // Remap rap_items.rab_snapshot_id
        if (!empty($sourceRapItems)) {
            foreach ($sourceRapItems as $ri) {
                if ($ri['rab_snapshot_id'] !== null && intval($ri['rab_snapshot_id']) > 0) {
                    if (!isset($maps['rab_snapshots'][$ri['rab_snapshot_id']])) {
                        throw new RuntimeException("Missing mapping for rap_items rab_snapshot_id: " . $ri['rab_snapshot_id']);
                    }
                    $newSnapId = $maps['rab_snapshots'][$ri['rab_snapshot_id']];
                    $newRapItemRecordId = $maps['rap_items'][$ri['id']];
                    dbExecute("UPDATE rap_items SET rab_snapshot_id = ? WHERE id = ?", [$newSnapId, $newRapItemRecordId]);
                }
            }
        }
        
        // Remap projects.rap_source_id (Section 20)
        if ($sourceProject['rap_source_id'] !== null && intval($sourceProject['rap_source_id']) > 0) {
            if (!isset($maps['rab_snapshots'][$sourceProject['rap_source_id']])) {
                throw new RuntimeException("Missing mapping for projects rap_source_id: " . $sourceProject['rap_source_id']);
            }
            $newRapSourceId = $maps['rab_snapshots'][$sourceProject['rap_source_id']];
            dbExecute("UPDATE projects SET rap_source_id = ? WHERE id = ?", [$newRapSourceId, $newProjectId]);
        } elseif ($sourceProject['rap_source_id'] === null) {
            dbExecute("UPDATE projects SET rap_source_id = NULL WHERE id = ?", [$newProjectId]);
        } else {
            dbExecute("UPDATE projects SET rap_source_id = 0 WHERE id = ?", [$newProjectId]);
        }
        
        // 10. Clone Requests (requests -> items, attachments, actuals, actual attachments)
        $reqUploadDir = __DIR__ . '/../uploads/requests/';
        $actUploadDir = __DIR__ . '/../uploads/actuals/';
        if (!is_dir($reqUploadDir)) mkdir($reqUploadDir, 0755, true);
        if (!is_dir($actUploadDir)) mkdir($actUploadDir, 0755, true);
        
        $requests = dbGetAll("SELECT * FROM requests WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($requests as $req) {
            $newReqId = dbInsert("
                INSERT INTO requests (
                    project_id, request_number, request_date, week_number, description, status,
                    total_amount, approved_amount, approved_by, approved_at, admin_notes, target_week,
                    rejection_reason, created_by, is_actualized, pm_approved_by, pm_approved_at, pm_notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $newProjectId, $req['request_number'], $req['request_date'], $req['week_number'], $req['description'], $req['status'],
                $req['total_amount'], $req['approved_amount'], $req['approved_by'], $req['approved_at'], $req['admin_notes'], $req['target_week'],
                $req['rejection_reason'], $req['created_by'], $req['is_actualized'], $req['pm_approved_by'], $req['pm_approved_at'], $req['pm_notes']
            ]);
            $maps['requests'][$req['id']] = intval($newReqId);
            
            $reqItems = dbGetAll("SELECT * FROM request_items WHERE request_id = ? ORDER BY id ASC", [$req['id']]);
            foreach ($reqItems as $ri) {
                $newSubcatId = null;
                if ($ri['subcategory_id'] !== null) {
                    if (!isset($maps['rab_subcategories'][$ri['subcategory_id']])) {
                        throw new RuntimeException("Missing mapping for request_items subcategory_id: " . $ri['subcategory_id']);
                    }
                    $newSubcatId = $maps['rab_subcategories'][$ri['subcategory_id']];
                }
                $newCatId = null;
                if ($ri['category_id'] !== null) {
                    if (!isset($maps['rab_categories'][$ri['category_id']])) {
                        throw new RuntimeException("Missing mapping for request_items category_id: " . $ri['category_id']);
                    }
                    $newCatId = $maps['rab_categories'][$ri['category_id']];
                }
                $newReqItemId = dbInsert("
                    INSERT INTO request_items (
                        request_id, subcategory_id, subcat_details, category_id, item_name,
                        item_code, item_type, unit, quantity, coefficient, unit_price, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $newReqId, $newSubcatId, $ri['subcat_details'], $newCatId, $ri['item_name'],
                    $ri['item_code'], $ri['item_type'], $ri['unit'], $ri['quantity'], $ri['coefficient'], $ri['unit_price'], $ri['notes']
                ]);
                $maps['request_items'][$ri['id']] = intval($newReqItemId);
            }
            
            $reqAtts = dbGetAll("SELECT * FROM request_attachments WHERE request_id = ? ORDER BY id ASC", [$req['id']]);
            foreach ($reqAtts as $att) {
                $srcFile = $reqUploadDir . $att['filename'];
                if (!file_exists($srcFile)) {
                    throw new RuntimeException("Source request attachment file not found: " . $att['filename']);
                }
                $ext = pathinfo($att['filename'], PATHINFO_EXTENSION);
                $newFilename = 'req_' . $newReqId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $destFile = $reqUploadDir . $newFilename;
                if (!copy($srcFile, $destFile)) {
                    throw new RuntimeException("Failed to copy request attachment from $srcFile to $destFile");
                }
                $createdFiles[] = $destFile;
                
                dbInsert("
                    INSERT INTO request_attachments (request_id, filename, original_name, file_type, file_size)
                    VALUES (?, ?, ?, ?, ?)
                ", [$newReqId, $newFilename, $att['original_name'], $att['file_type'], $att['file_size']]);
            }
            
            $actual = dbGetRow("SELECT * FROM request_actuals WHERE request_id = ?", [$req['id']]);
            if ($actual) {
                $newActId = dbInsert("
                    INSERT INTO request_actuals (
                        request_id, remaining_upah, remaining_material, remaining_alat,
                        consumed_upah, consumed_material, consumed_alat,
                        notes_upah, notes_material, notes_alat, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $newReqId, $actual['remaining_upah'], $actual['remaining_material'], $actual['remaining_alat'],
                    $actual['consumed_upah'], $actual['consumed_material'], $actual['consumed_alat'],
                    $actual['notes_upah'], $actual['notes_material'], $actual['notes_alat'], $actual['created_by']
                ]);
                $maps['request_actuals'][$actual['id']] = intval($newActId);
                
                $actAtts = dbGetAll("SELECT * FROM request_actual_attachments WHERE request_actual_id = ? ORDER BY id ASC", [$actual['id']]);
                foreach ($actAtts as $att) {
                    $srcFile = $actUploadDir . $att['filename'];
                    if (!file_exists($srcFile)) {
                        throw new RuntimeException("Source actual attachment file not found: " . $att['filename']);
                    }
                    $ext = pathinfo($att['filename'], PATHINFO_EXTENSION);
                    $newFilename = 'act_' . $newActId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $destFile = $actUploadDir . $newFilename;
                    if (!copy($srcFile, $destFile)) {
                        throw new RuntimeException("Failed to copy actual attachment from $srcFile to $destFile");
                    }
                    $createdFiles[] = $destFile;
                    
                    dbInsert("
                        INSERT INTO request_actual_attachments (request_actual_id, filename, original_name, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$newActId, $newFilename, $att['original_name'], $att['file_type'], $att['file_size']]);
                }
            }
        }
        
        // 11. Clone Weekly Progress (weekly_progress)
        $progressList = dbGetAll("SELECT * FROM weekly_progress WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($progressList as $prog) {
            if (!isset($maps['rab_subcategories'][$prog['subcategory_id']])) {
                throw new RuntimeException("Missing mapping for weekly_progress subcategory_id: " . $prog['subcategory_id']);
            }
            $newSubcatId = $maps['rab_subcategories'][$prog['subcategory_id']];
            $newProgId = dbInsert("
                INSERT INTO weekly_progress (project_id, subcategory_id, week_number, week_start, week_end, realization_amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ", [$newProjectId, $newSubcatId, $prog['week_number'], $prog['week_start'], $prog['week_end'], $prog['realization_amount'], $prog['notes'], $prog['created_by']]);
            $maps['weekly_progress'][$prog['id']] = intval($newProgId);
        }
        
        // 12. Clone Project Images (project_images)
        $imgUploadDir = __DIR__ . '/../uploads/project_images/';
        if (!is_dir($imgUploadDir)) mkdir($imgUploadDir, 0755, true);
        
        $images = dbGetAll("SELECT * FROM project_images WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($images as $img) {
            $srcFile = $imgUploadDir . $img['filename'];
            if (!file_exists($srcFile)) {
                throw new RuntimeException("Source project image file not found: " . $img['filename']);
            }
            $ext = pathinfo($img['filename'], PATHINFO_EXTENSION);
            $newFilename = 'proj_' . $newProjectId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destFile = $imgUploadDir . $newFilename;
            if (!copy($srcFile, $destFile)) {
                throw new RuntimeException("Failed to copy project image from $srcFile to $destFile");
            }
            $createdFiles[] = $destFile;
            
            $newImgId = dbInsert("
                INSERT INTO project_images (project_id, filename, original_name, description, uploaded_by)
                VALUES (?, ?, ?, ?, ?)
            ", [$newProjectId, $newFilename, $img['original_name'], $img['description'], $img['uploaded_by']]);
            $maps['project_images'][$img['id']] = intval($newImgId);
        }
        
        // 13. Clone Document Folders (project_document_folders) & Documents (project_documents)
        $docUploadDir = __DIR__ . '/../uploads/project_documents/';
        if (!is_dir($docUploadDir)) mkdir($docUploadDir, 0755, true);
        
        $allFolders = dbGetAll("SELECT * FROM project_document_folders WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        $foldersByParent = [];
        foreach ($allFolders as $f) {
            $pId = ($f['parent_id'] !== null) ? intval($f['parent_id']) : 0;
            $foldersByParent[$pId][] = $f;
        }
        
        $cloneFoldersRecursively = function($parentSourceId, $parentNewId) use (&$cloneFoldersRecursively, &$foldersByParent, $newProjectId, &$maps) {
            if (empty($foldersByParent[$parentSourceId])) return;
            foreach ($foldersByParent[$parentSourceId] as $folder) {
                $newFolderId = dbInsert("
                    INSERT INTO project_document_folders (project_id, parent_id, name, created_by)
                    VALUES (?, ?, ?, ?)
                ", [$newProjectId, $parentNewId, $folder['name'], $folder['created_by']]);
                $maps['project_document_folders'][$folder['id']] = intval($newFolderId);
                $cloneFoldersRecursively(intval($folder['id']), intval($newFolderId));
            }
        };
        $cloneFoldersRecursively(0, null);
        
        $docs = dbGetAll("SELECT * FROM project_documents WHERE project_id = ? ORDER BY id ASC", [$sourceProjectId]);
        foreach ($docs as $doc) {
            $newFolderId = null;
            if ($doc['folder_id'] !== null) {
                if (!isset($maps['project_document_folders'][$doc['folder_id']])) {
                    throw new RuntimeException("Missing mapping for project_documents folder_id: " . $doc['folder_id']);
                }
                $newFolderId = $maps['project_document_folders'][$doc['folder_id']];
            }
            
            $srcFile = $docUploadDir . $doc['filename'];
            if (!file_exists($srcFile)) {
                throw new RuntimeException("Source project document file not found: " . $doc['filename']);
            }
            $ext = pathinfo($doc['filename'], PATHINFO_EXTENSION);
            $newFilename = 'doc_' . $newProjectId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destFile = $docUploadDir . $newFilename;
            if (!copy($srcFile, $destFile)) {
                throw new RuntimeException("Failed to copy project document from $srcFile to $destFile");
            }
            $createdFiles[] = $destFile;
            
            $newDocId = dbInsert("
                INSERT INTO project_documents (
                    project_id, folder_id, title, filename, original_name,
                    file_type, file_size, category, description, uploaded_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $newProjectId, $newFolderId, $doc['title'], $newFilename, $doc['original_name'],
                $doc['file_type'], $doc['file_size'], $doc['category'], $doc['description'], $doc['uploaded_by']
            ]);
            $maps['project_documents'][$doc['id']] = intval($newDocId);
        }
        
        // 14. Integrity Verification before commit
        $checks = [
            ['table' => 'project_items', 'sourceCount' => count($items), 'newQuery' => "SELECT COUNT(*) as cnt FROM project_items WHERE project_id = ?"],
            ['table' => 'project_ahsp', 'sourceCount' => count($ahsps), 'newQuery' => "SELECT COUNT(*) as cnt FROM project_ahsp WHERE project_id = ?"],
            ['table' => 'rab_categories', 'sourceCount' => count($categories), 'newQuery' => "SELECT COUNT(*) as cnt FROM rab_categories WHERE project_id = ?"],
            ['table' => 'rab_snapshots', 'sourceCount' => count($snapshots), 'newQuery' => "SELECT COUNT(*) as cnt FROM rab_snapshots WHERE project_id = ?"],
            ['table' => 'requests', 'sourceCount' => count($requests), 'newQuery' => "SELECT COUNT(*) as cnt FROM requests WHERE project_id = ?"],
            ['table' => 'weekly_progress', 'sourceCount' => count($progressList), 'newQuery' => "SELECT COUNT(*) as cnt FROM weekly_progress WHERE project_id = ?"],
            ['table' => 'project_images', 'sourceCount' => count($images), 'newQuery' => "SELECT COUNT(*) as cnt FROM project_images WHERE project_id = ?"],
            ['table' => 'project_documents', 'sourceCount' => count($docs), 'newQuery' => "SELECT COUNT(*) as cnt FROM project_documents WHERE project_id = ?"],
        ];
        foreach ($checks as $chk) {
            $newCnt = intval(dbGetRow($chk['newQuery'], [$newProjectId])['cnt'] ?? 0);
            if ($newCnt !== $chk['sourceCount']) {
                throw new RuntimeException("Verification failed for {$chk['table']}: expected {$chk['sourceCount']}, got {$newCnt}");
            }
        }
        
        $db->commit();
        return $newProjectId;
        
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        foreach ($createdFiles as $filePath) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
        
        error_log("Duplicate Project Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw $e;
    }
}



