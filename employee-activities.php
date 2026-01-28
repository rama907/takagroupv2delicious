<?php
require_once 'config.php';

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi formatDuration dan getRoleDisplayName ada
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m";
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($role) {
        $roles = [
            'ceo' => 'CEO',
            'direktur' => 'Direktur',
            'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager',
            'barista' => 'Barista',
            'waiters' => 'Waiters',
            'karyawan' => 'Karyawan',
            'magang' => 'Magang',
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

// Dapatkan ringkasan aktivitas karyawan (mengambil data keseluruhan)
// MODIFIKASI QUERY: Menggunakan tabel sales_data dan cooking_data secara terpisah
$stmt = $conn->query("
    SELECT
        e.id,
        e.name,
        e.role,
        e.is_on_duty,
        COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
        
        -- Kolom Sales (Dari sales_data)
        COALESCE(sales_summary.sales_western, 0) as paket_western_sales,
        COALESCE(sales_summary.sales_nusantara, 0) as paket_nusantara_sales,
        COALESCE(sales_summary.sales_kids, 0) as paket_kids_meal_sales,
        COALESCE(sales_summary.sales_happy_bites, 0) as happy_bites_sales, -- NEW
        COALESCE(sales_summary.sales_royale, 0) as paket_royale_sales,
        
        -- Kolom Masak (Dari cooking_data)
        COALESCE(prep_summary.prep_western, 0) as paket_western_prep, 
        COALESCE(prep_summary.prep_nusantara, 0) as paket_nusantara_prep,  
        COALESCE(prep_summary.prep_kids, 0) as paket_kids_meal_prep,
        COALESCE(prep_summary.prep_happy_bites, 0) as happy_bites_prep, -- NEW
        COALESCE(prep_summary.prep_royale, 0) as paket_royale_prep,
        
        -- TOTAL CALCULATED FIELDS
        (
            COALESCE(sales_summary.sales_western, 0) + 
            COALESCE(sales_summary.sales_nusantara, 0) + 
            COALESCE(sales_summary.sales_kids, 0) +
            COALESCE(sales_summary.sales_happy_bites, 0) +
            COALESCE(sales_summary.sales_royale, 0)
        ) AS total_sales_packages,
        
        (
            COALESCE(prep_summary.prep_western, 0) + 
            COALESCE(prep_summary.prep_nusantara, 0) + 
            COALESCE(prep_summary.prep_kids, 0) +
            COALESCE(prep_summary.prep_happy_bites, 0) +
            COALESCE(prep_summary.prep_royale, 0)
        ) AS total_prep_packages

    FROM employees e
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs
        WHERE status = 'completed'
        GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    
    -- Join ke sales_data untuk Data Penjualan
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(paket_western) as sales_western,
            SUM(paket_nusantara) as sales_nusantara,
            SUM(paket_kids) as sales_kids,
            SUM(happy_bites) as sales_happy_bites,
            SUM(paket_royale) as sales_royale
        FROM sales_data
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    
    -- Join ke cooking_data untuk Data Masak
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(paket_western) as prep_western,
            SUM(paket_nusantara) as prep_nusantara,
            SUM(paket_kids) as prep_kids,
            SUM(happy_bites) as prep_happy_bites,
            SUM(paket_royale) as prep_royale
        FROM cooking_data
        GROUP BY employee_id
    ) as prep_summary ON e.id = prep_summary.employee_id
    
    WHERE e.status = 'active'
    ORDER BY
        CASE e.role
            WHEN 'ceo' THEN 1
            WHEN 'direktur' THEN 2
            WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4
            WHEN 'barista' THEN 5
            WHEN 'waiters' THEN 6
            WHEN 'karyawan' THEN 7
            WHEN 'magang' THEN 8
            ELSE 9
        END,
        e.name
");

if ($stmt === false) {
    die("Gagal menjalankan query: " . $conn->error);
}

$employee_activities = $stmt->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Hitung Total Keseluruhan (OVERALL SUMMARY) ---

// Total Penjualan Paket
$total_western_sales = array_sum(array_column($employee_activities, 'paket_western_sales'));
$total_nusantara_sales = array_sum(array_column($employee_activities, 'paket_nusantara_sales'));
$total_kids_meal_sales = array_sum(array_column($employee_activities, 'paket_kids_meal_sales'));
$total_happy_bites_sales = array_sum(array_column($employee_activities, 'happy_bites_sales')); // NEW
$total_royale_sales = array_sum(array_column($employee_activities, 'paket_royale_sales')); 
$total_penjualan_paket_keseluruhan = $total_western_sales + $total_nusantara_sales + $total_kids_meal_sales + $total_happy_bites_sales + $total_royale_sales;

// Total Masak Paket
$total_western_prep = array_sum(array_column($employee_activities, 'paket_western_prep'));
$total_nusantara_prep = array_sum(array_column($employee_activities, 'paket_nusantara_prep'));
$total_kids_meal_prep = array_sum(array_column($employee_activities, 'paket_kids_meal_prep'));
$total_happy_bites_prep = array_sum(array_column($employee_activities, 'happy_bites_prep')); // NEW
$total_royale_prep = array_sum(array_column($employee_activities, 'paket_royale_prep')); 
$total_masak_paket_keseluruhan = $total_western_prep + $total_nusantara_prep + $total_kids_meal_prep + $total_happy_bites_prep + $total_royale_prep;


// === START EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    
    // Gunakan query yang sama persis dengan di atas untuk konsistensi data
    $export_stmt_sql = "
        SELECT
            e.id, e.name, e.role, e.is_on_duty,
            COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
            
            COALESCE(sales_summary.sales_western, 0) as paket_western_sales,
            COALESCE(sales_summary.sales_nusantara, 0) as paket_nusantara_sales,
            COALESCE(sales_summary.sales_kids, 0) as paket_kids_meal_sales,
            COALESCE(sales_summary.sales_happy_bites, 0) as happy_bites_sales,
            COALESCE(sales_summary.sales_royale, 0) as paket_royale_sales,

            COALESCE(prep_summary.prep_western, 0) as paket_western_prep, 
            COALESCE(prep_summary.prep_nusantara, 0) as paket_nusantara_prep,  
            COALESCE(prep_summary.prep_kids, 0) as paket_kids_meal_prep,
            COALESCE(prep_summary.prep_happy_bites, 0) as happy_bites_prep,
            COALESCE(prep_summary.prep_royale, 0) as paket_royale_prep

        FROM employees e
        LEFT JOIN (
            SELECT employee_id, SUM(duration_minutes) as total_duty_minutes FROM duty_logs WHERE status = 'completed' GROUP BY employee_id
        ) as duty_summary ON e.id = duty_summary.employee_id
        LEFT JOIN (
            SELECT 
                employee_id, 
                SUM(paket_western) as sales_western, 
                SUM(paket_nusantara) as sales_nusantara, 
                SUM(paket_kids) as sales_kids, 
                SUM(happy_bites) as sales_happy_bites,
                SUM(paket_royale) as sales_royale
            FROM sales_data GROUP BY employee_id
        ) as sales_summary ON e.id = sales_summary.employee_id
        LEFT JOIN (
            SELECT 
                employee_id, 
                SUM(paket_western) as prep_western, 
                SUM(paket_nusantara) as prep_nusantara, 
                SUM(paket_kids) as prep_kids, 
                SUM(happy_bites) as prep_happy_bites,
                SUM(paket_royale) as prep_royale
            FROM cooking_data GROUP BY employee_id
        ) as prep_summary ON e.id = prep_summary.employee_id
        WHERE e.status = 'active'
        ORDER BY e.name
    ";

    $export_stmt = $conn->query($export_stmt_sql);
    if ($export_stmt === false) { die("Gagal menjalankan query ekspor: " . $conn->error); }
    $export_data_raw = $export_stmt->fetch_all(MYSQLI_ASSOC);
    $export_stmt->close();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="aktivitas_anggota_delicious_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // Definisikan CSV headers (Update with Happy Bites)
    $headers = [
        'Nama',
        'Jabatan',
        'Status On Duty',
        'Total Jam Kerja (Menit)',
        'Total Penjualan Paket',
        'Western (Jual)', 
        'Nusantara (Jual)',
        'Kids Meal (Jual)',
        'Happy Bites (Jual)',
        'Royale (Jual)',
        'Total Masak Paket',
        'Western (Masak)',
        'Nusantara (Masak)',
        'Kids Meal (Masak)',
        'Happy Bites (Masak)',
        'Royale (Masak)',
    ];
    fputcsv($output, $headers);

    // Tulis baris data
    foreach ($export_data_raw as $row) {
        $total_sales = $row['paket_western_sales'] + $row['paket_nusantara_sales'] + $row['paket_kids_meal_sales'] + $row['happy_bites_sales'] + $row['paket_royale_sales'];
        $total_prep = $row['paket_western_prep'] + $row['paket_nusantara_prep'] + $row['paket_kids_meal_prep'] + $row['happy_bites_prep'] + $row['paket_royale_prep'];
        
        $data_row = [
            htmlspecialchars_decode($row['name']), 
            getRoleDisplayName($row['role']),
            $row['is_on_duty'] ? 'On Duty' : 'Off Duty',
            $row['total_duty_minutes'],
            $total_sales,
            $row['paket_western_sales'], 
            $row['paket_nusantara_sales'],
            $row['paket_kids_meal_sales'],
            $row['happy_bites_sales'],
            $row['paket_royale_sales'],
            $total_prep,
            $row['paket_western_prep'],
            $row['paket_nusantara_prep'], 
            $row['paket_kids_meal_prep'], 
            $row['happy_bites_prep'],
            $row['paket_royale_prep'], 
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit;
}
// === AKHIR LOGIKA EKSPOR ===
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Anggota - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📊</span>
                    Aktivitas Anggota
                </h1>
                <p>Ringkasan aktivitas dan performa semua anggota</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="employee-activities.php?export=spreadsheet" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Data Spreadsheet
                    </a>
                </div>
            </div>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Total Jam Kerja Keseluruhan</h4>
                        <p class="summary-value">
                            <?= formatDuration(array_sum(array_column($employee_activities, 'total_duty_minutes'))) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">💰</div>
                    <div class="summary-content">
                        <h4>Total Penjualan Paket</h4>
                        <p class="summary-value">
                            <?= $total_penjualan_paket_keseluruhan ?> Paket
                        </p>
                        <p class="stat-breakdown" style="font-size: 0.9em; margin-top: 0.5rem; text-align: left;">
                            <span>Western: <strong><?= $total_western_sales ?></strong></span>
                            <span>Nusantara: <strong><?= $total_nusantara_sales ?></strong></span>
                            <span>Kids: <strong><?= $total_kids_meal_sales ?></strong></span>
                            <span>Happy: <strong><?= $total_happy_bites_sales ?></strong></span>
                            <span>Royale: <strong><?= $total_royale_sales ?></strong></span>
                        </p>
                    </div>
                </div>
                 <div class="summary-card">
                    <div class="summary-icon" style="color: var(--warning-color);">🔪</div>
                    <div class="summary-content">
                        <h4>Total Masak Paket</h4>
                        <p class="summary-value">
                            <?= $total_masak_paket_keseluruhan ?> Paket
                        </p>
                        <p class="stat-breakdown" style="font-size: 0.9em; margin-top: 0.5rem; text-align: left;">
                            <span>Western: <strong><?= $total_western_prep ?></strong></span>
                            <span>Nusantara: <strong><?= $total_nusantara_prep ?></strong></span>
                            <span>Kids: <strong><?= $total_kids_meal_prep ?></strong></span>
                            <span>Happy: <strong><?= $total_happy_bites_prep ?></strong></span>
                            <span>Royale: <strong><?= $total_royale_prep ?></strong></span>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">✅</div>
                    <div class="summary-content">
                        <h4>Anggota Aktif On Duty</h4>
                        <p class="summary-value">
                            <?= count(array_filter($employee_activities, function($emp) { return $emp['is_on_duty']; })) ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Ringkasan Aktivitas per Anggota</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Status</th>
                                    <th>Total Jam Kerja</th>
                                    <th>Total Jual</th> 
                                    <th>Jual (West)</th>
                                    <th>Jual (Nusa)</th>
                                    <th>Jual (Kids)</th>
                                    <th>Jual (Happy)</th>
                                    <th>Jual (Royale)</th> 
                                    <th>Total Masak</th> 
                                    <th>Masak (West)</th>
                                    <th>Masak (Nusa)</th>
                                    <th>Masak (Kids)</th>
                                    <th>Masak (Happy)</th>
                                    <th>Masak (Royale)</th> 
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employee_activities)): ?>
                                    <tr>
                                        <td colspan="16" class="no-data">Belum ada data aktivitas anggota.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employee_activities as $activity): ?>
                                    <tr>
                                        <td data-label="Nama">
                                            <div class="employee-name-cell">
                                                <span class="employee-avatar-small">
                                                    <?= strtoupper(substr(htmlspecialchars($activity['name']), 0, 1)) ?>
                                                </span>
                                                <span><?= htmlspecialchars($activity['name']) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Jabatan">
                                            <span class="role-badge role-<?= htmlspecialchars($activity['role']) ?>">
                                                <?= htmlspecialchars(getRoleDisplayName($activity['role'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <div class="status-cell">
                                                <span class="status-indicator <?= $activity['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                                <span><?= $activity['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Total Jam Kerja">
                                            <strong><?= formatDuration($activity['total_duty_minutes']) ?></strong>
                                        </td>
                                        
                                        <td data-label="Total Jual">
                                            <strong><?= $activity['total_sales_packages'] ?></strong>
                                        </td>
                                        <td data-label="Jual (West)"><?= $activity['paket_western_sales'] ?></td>
                                        <td data-label="Jual (Nusa)"><?= $activity['paket_nusantara_sales'] ?></td>
                                        <td data-label="Jual (Kids)"><?= $activity['paket_kids_meal_sales'] ?></td>
                                        <td data-label="Jual (Happy)"><?= $activity['happy_bites_sales'] ?></td>
                                        <td data-label="Jual (Royale)"><?= $activity['paket_royale_sales'] ?></td>
                                        
                                        <td data-label="Total Masak">
                                            <strong><?= $activity['total_prep_packages'] ?></strong>
                                        </td>
                                        <td data-label="Masak (West)"><?= $activity['paket_western_prep'] ?></td>
                                        <td data-label="Masak (Nusa)"><?= $activity['paket_nusantara_prep'] ?></td>
                                        <td data-label="Masak (Kids)"><?= $activity['paket_kids_meal_prep'] ?></td>
                                        <td data-label="Masak (Happy)"><?= $activity['happy_bites_prep'] ?></td>
                                        <td data-label="Masak (Royale)"><?= $activity['paket_royale_prep'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>