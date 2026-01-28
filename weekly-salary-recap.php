<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- Helper Functions ---
function roundToNearestHour($minutes) {
    return round($minutes / 60);
}

function formatCurrency($amount) { 
    return '$ ' . number_format($amount, 0, '.', ','); 
}

// --- KONFIGURASI GAJI (SAMA DENGAN SALARY-RECAP) ---
$RATE_PER_JAM = 200;       
$RATE_BONUS_PAKET = 200;   
$MIN_DUTY_HOURS_REQUIRED = 15; 
$TARGET_SALES_MAGANG = 35;     
$TARGET_SALES_STAFF = 60;      

// --- Filter Tanggal Mingguan ---
// Default: Senin minggu ini s/d Minggu minggu ini
$today = new DateTime();
$default_start = clone $today;
if ($today->format('N') != 1) { 
    $default_start->modify('last Monday'); 
}
$default_end = clone $default_start;
$default_end->modify('+6 days');

$start_date = $_GET['start_date'] ?? $default_start->format('Y-m-d');
$end_date = $_GET['end_date'] ?? $default_end->format('Y-m-d');

// --- Query Data Mingguan ---
// Mengambil data Duty dan Sales yang TERJADI DALAM RENTANG TANGGAL
$query = "
    SELECT e.id, e.name, e.role,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.total_sales, 0) as total_sales_packages
    FROM employees e
    -- 1. Join Log Duty Mingguan
    LEFT JOIN (
        SELECT employee_id, SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs 
        WHERE status = 'completed' 
        AND DATE(duty_start) BETWEEN ? AND ?
        GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    -- 2. Join Sales Data Mingguan (UPDATE: Struktur Baru + Happy Bites)
    LEFT JOIN (
        SELECT employee_id,
            (SUM(paket_western) + SUM(paket_nusantara) + SUM(paket_kids) + SUM(happy_bites) + SUM(paket_royale)) as total_sales
        FROM sales_data
        WHERE date BETWEEN ? AND ?
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active'
    ORDER BY FIELD(e.role, 'ceo', 'direktur', 'wakil_direktur', 'manager', 'chef', 'waiters', 'karyawan', 'magang'), e.name
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
$employees_raw = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Proses Perhitungan ---
$report_data = [];
$total_payout = 0;

foreach ($employees_raw as $emp) {
    $role = $emp['role'];
    $duty_minutes = $emp['total_duty_minutes'];
    $sales_count = (int)$emp['total_sales_packages'];
    
    $rounded_hours = roundToNearestHour($duty_minutes);
    
    // Hitung Gaji
    $gaji_duty = 0;
    $bonus_sales = 0;
    $keterangan = [];
    $is_qualified = true;

    // 1. Cek Jam Duty
    if ($rounded_hours >= $MIN_DUTY_HOURS_REQUIRED) {
        $gaji_duty = $rounded_hours * $RATE_PER_JAM;
    } else {
        // Jika jam kurang, apakah gaji hangus? (Sesuai salary-recap: Hangus)
        $gaji_duty = 0; 
        if ($rounded_hours > 0) {
            $keterangan[] = "Jam < $MIN_DUTY_HOURS_REQUIRED";
            $is_qualified = false;
        }
    }

    // 2. Cek Bonus Sales
    $target = ($role === 'magang') ? $TARGET_SALES_MAGANG : $TARGET_SALES_STAFF;
    if ($sales_count > $target) {
        $bonus_sales = $sales_count * $RATE_BONUS_PAKET;
        $keterangan[] = "Bonus OK";
    }

    $total_individual = $gaji_duty + $bonus_sales;
    $total_payout += $total_individual;

    $report_data[] = [
        'name' => $emp['name'],
        'role' => $emp['role'],
        'hours_raw' => $duty_minutes / 60,
        'hours_rounded' => $rounded_hours,
        'sales_count' => $sales_count,
        'salary_duty' => $gaji_duty,
        'salary_bonus' => $bonus_sales,
        'total' => $total_individual,
        'note' => implode(", ", $keterangan)
    ];
}

// === EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rekap_mingguan_' . $start_date . '_to_' . $end_date . '.csv"');
    
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nama', 'Jabatan', 'Jam Duty (Bulat)', 'Total Sales', 'Gaji Duty ($)', 'Bonus Sales ($)', 'Total Terima ($)', 'Catatan']);
    
    foreach ($report_data as $row) {
        fputcsv($out, [
            $row['name'],
            getRoleDisplayName($row['role']),
            $row['hours_rounded'],
            $row['sales_count'],
            number_format($row['salary_duty'], 0, '.', ','),
            number_format($row['salary_bonus'], 0, '.', ','),
            number_format($row['total'], 0, '.', ','),
            $row['note']
        ]);
    }
    fclose($out);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji Mingguan - Delicious</title>
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
                    <span class="page-icon">📅</span>
                    Rekap Gaji Mingguan
                </h1>
                <p>Periode: <strong><?= date('d M Y', strtotime($start_date)) ?></strong> s/d <strong><?= date('d M Y', strtotime($end_date)) ?></strong></p>
            </div>

            <div class="card full-width" style="margin-bottom: 20px;">
                <div class="card-content">
                    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Mulai Tanggal</label>
                            <input type="date" name="start_date" value="<?= $start_date ?>" class="form-input">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Sampai Tanggal</label>
                            <input type="date" name="end_date" value="<?= $end_date ?>" class="form-input">
                        </div>
                        <button type="submit" class="btn btn-primary">Tampilkan</button>
                        <a href="weekly-salary-recap.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&export=csv" class="btn btn-success">
                            <span class="btn-icon">⬇️</span> Unduh CSV
                        </a>
                    </form>
                </div>
            </div>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">💵</div>
                    <div class="summary-content">
                        <h4>Estimasi Pengeluaran (Periode Ini)</h4>
                        <p class="summary-value"><?= formatCurrency($total_payout) ?></p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">📅</div>
                    <div class="summary-content">
                        <h4>Durasi</h4>
                        <p class="summary-value">
                            <?php 
                                $diff = (new DateTime($start_date))->diff(new DateTime($end_date));
                                echo ($diff->days + 1) . " Hari";
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Rincian Per Anggota</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Jam Duty</th>
                                    <th>Sales (Paket)</th>
                                    <th>Gaji Duty</th>
                                    <th>Bonus Sales</th>
                                    <th>Total ($)</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($report_data)): ?>
                                    <tr><td colspan="8" class="no-data">Tidak ada data pada periode ini.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="role-badge role-<?= $row['role'] ?>">
                                                <?= getRoleDisplayName($row['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= $row['hours_rounded'] ?></strong> 
                                            <small style="color:#888;">(<?= number_format($row['hours_raw'], 1) ?>)</small>
                                        </td>
                                        <td>
                                            <strong><?= $row['sales_count'] ?></strong>
                                        </td>
                                        <td><?= formatCurrency($row['salary_duty']) ?></td>
                                        <td><?= formatCurrency($row['salary_bonus']) ?></td>
                                        <td style="font-size: 1.1em; font-weight: bold; color: var(--success-color);">
                                            <?= formatCurrency($row['total']) ?>
                                        </td>
                                        <td>
                                            <small><?= $row['note'] ?></small>
                                        </td>
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