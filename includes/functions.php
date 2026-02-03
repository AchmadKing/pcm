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
    
    // Convert strings to DateTime if needed
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

