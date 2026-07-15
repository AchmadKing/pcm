<?php
/**
 * Helper Functions
 * PCM - Project Cost Management System
 */

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
 * Format volume - up to 4 decimals but trim trailing zeros
 * Examples: 123,4500 → 123,45; 123,0040 → 123,004; 123,4567 → 123,4567
 * @param float $number
 * @return string
 */
function formatVolume($number) {
    // Format with 4 decimals first
    $formatted = number_format($number, 4, ',', '.');
    
    // Remove trailing zeros after decimal point
    // Split by comma (decimal separator)
    $parts = explode(',', $formatted);
    if (count($parts) === 2) {
        // Remove trailing zeros from decimal part
        $decimal = rtrim($parts[1], '0');
        if (empty($decimal)) {
            // No decimals left, return integer part only
            return $parts[0];
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
 * Sanitize input
 * @param string $input
 * @return string
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
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
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 * @return array|null
 */
function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
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
    
    $ahspIds = dbGetAll("SELECT DISTINCT ahsp_id FROM project_ahsp_details_rap WHERE item_id = ?", [$itemId]);
    foreach ($ahspIds as $row) {
        recalculateRapAhspPrice($row['ahsp_id']);
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
