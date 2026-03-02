<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- Definisi Logika Gaji & Konstanta (SESUAI SALARY-RECAP BARU) ---
function roundToNearestHour($minutes) {
    return round($minutes / 60);
}

// Konfigurasi Gaji Baru
$RATE_PER_JAM = 200;       // Gaji per jam
$RATE_BONUS_PAKET = 200;   // Bonus per paket
$MIN_DUTY_HOURS_REQUIRED = 15; // Min 15 jam duty
$TARGET_SALES_MAGANG = 35;     // Target Magang > 35
$TARGET_SALES_STAFF = 60;      // Target Staff > 60

// --- 1. Hitung Total Pengeluaran Gaji (Update Logika Baru) ---
$total_payroll_expenditure = 0;

// Query diperbarui: Mengambil data Duty DAN Sales per karyawan
// UPDATE: Menambahkan Sweet Coffee dan Matcha Misu ke total sales untuk bonus
$employees_raw_data_payroll = $conn->query("
    SELECT e.id, e.name, e.role,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.total_sales, 0) as total_sales_packages
    FROM employees e
    LEFT JOIN (
        SELECT employee_id, SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs WHERE status = 'completed' GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    LEFT JOIN (
        SELECT employee_id,
            -- Menjumlahkan semua paket penjualan TERMASUK MENU BARU
            (
                SUM(paket_western) + 
                SUM(paket_nusantara) + 
                SUM(paket_kids) + 
                SUM(happy_bites) + 
                SUM(paket_royale) +
                SUM(paket_sweet_coffee) + 
                SUM(paket_matchamisu)
            ) as total_sales
        FROM sales_data
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active'
");

if ($employees_raw_data_payroll) {
    while ($employee = $employees_raw_data_payroll->fetch_assoc()) {
        $employee_role = $employee['role'];
        $rounded_duty_hours = roundToNearestHour($employee['total_duty_minutes']);
        $total_sales = (int)$employee['total_sales_packages'];
        
        $gaji_duty = 0;
        $bonus_sales = 0;

        // A. Hitung Gaji Duty (Min 15 Jam)
        if ($rounded_duty_hours >= $MIN_DUTY_HOURS_REQUIRED) {
            $gaji_duty = $rounded_duty_hours * $RATE_PER_JAM;
        }

        // B. Hitung Bonus Penjualan
        $target_sales = ($employee_role === 'magang') ? $TARGET_SALES_MAGANG : $TARGET_SALES_STAFF;
        if ($total_sales > $target_sales) {
            $bonus_sales = $total_sales * $RATE_BONUS_PAKET;
        }

        // Total Gaji Orang Ini
        $total_gajian = $gaji_duty + $bonus_sales;
        
        // Akumulasi ke Pengeluaran Total
        $total_payroll_expenditure += $total_gajian;
    }
}

// === 2. PENETAPAN HARGA & PEMBAGIAN HASIL (80/20) ===
// Harga Paket (Pastikan sesuai dengan kebijakan resto)
$price_western = 2300;
$price_nusantara = 2100;
$price_kids_meal = 2000;
$price_happy_bites = 1500; 
$price_royale = 2300;
// HARGA BARU (Silakan sesuaikan jika perlu)
$price_sweet_coffee = 2000; 
$price_matchamisu = 2000;

$company_ratio = 0.8; 
$employee_ratio = 0.2; 

$overall_total_income = 0; 
$company_share_total = 0;  
$employee_commission_total = 0; 

// Query Total Pendapatan (Revenue)
// UPDATE: Menambahkan kolom baru
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(paket_western), 0) as sum_western,
        COALESCE(SUM(paket_nusantara), 0) as sum_nusantara,
        COALESCE(SUM(paket_kids), 0) as sum_kids_meal,
        COALESCE(SUM(happy_bites), 0) as sum_happy_bites,
        COALESCE(SUM(paket_royale), 0) as sum_royale,
        COALESCE(SUM(paket_sweet_coffee), 0) as sum_sweet_coffee,
        COALESCE(SUM(paket_matchamisu), 0) as sum_matchamisu
    FROM sales_data
");

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $overall_total_income = ($result['sum_western'] * $price_western) + 
                                ($result['sum_nusantara'] * $price_nusantara) + 
                                ($result['sum_kids_meal'] * $price_kids_meal) +
                                ($result['sum_happy_bites'] * $price_happy_bites) +
                                ($result['sum_royale'] * $price_royale) +
                                ($result['sum_sweet_coffee'] * $price_sweet_coffee) +
                                ($result['sum_matchamisu'] * $price_matchamisu);
        
        $company_share_total = $overall_total_income * $company_ratio;
        $employee_commission_total = $overall_total_income * $employee_ratio;
    }
}

// Profit Bersih = (80% Hak Perusahaan) - (Total Gaji Baru)
$net_income = $company_share_total - $total_payroll_expenditure;

// --- 3. Data Grafik ---
$chart_data_from_db = [];
$today = new DateTime();
$start_of_week = clone $today;
if ($start_of_week->format('N') != 1) $start_of_week->modify('last Monday');
$end_of_week = clone $start_of_week; $end_of_week->modify('+6 days');

// UPDATE: Menambahkan kolom baru ke query grafik
$stmt_daily = $conn->prepare("
    SELECT 
        sd.date, 
        SUM(sd.paket_western) as sw, 
        SUM(sd.paket_nusantara) as sn, 
        SUM(sd.paket_kids) as sk,
        SUM(sd.happy_bites) as sh,
        SUM(sd.paket_royale) as sr,
        SUM(sd.paket_sweet_coffee) as ssc,
        SUM(sd.paket_matchamisu) as smm
    FROM sales_data sd 
    WHERE sd.date BETWEEN ? AND ? 
    GROUP BY sd.date
");

if ($stmt_daily) {
    $stmt_daily->bind_param("ss", $start_of_week->format('Y-m-d'), $end_of_week->format('Y-m-d'));
    $stmt_daily->execute();
    $res = $stmt_daily->get_result();
    while ($r = $res->fetch_assoc()) {
        $omset = ($r['sw'] * $price_western) + 
                 ($r['sn'] * $price_nusantara) + 
                 ($r['sk'] * $price_kids_meal) +
                 ($r['sh'] * $price_happy_bites) +
                 ($r['sr'] * $price_royale) +
                 ($r['ssc'] * $price_sweet_coffee) +
                 ($r['smm'] * $price_matchamisu);
        $chart_data_from_db[$r['date']] = $omset * $company_ratio;
    }
}
$chart_labels = []; $chart_data_revenue = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $start_of_week; $d->modify("+{$i} days");
    $chart_labels[] = $d->format('D, d M');
    $chart_data_revenue[] = $chart_data_from_db[$d->format('Y-m-d')] ?? 0;
}

// --- 4. LOGS & RINGKASAN PER ANGGOTA ---
$omset_logs = [];
$member_summary = []; 

// UPDATE: Menambahkan kolom baru ke query logs
$stmt_logs = $conn->query("
    SELECT sd.date, sd.input_time, e.name as employee_name,
        (
            (sd.paket_western * {$price_western}) + 
            (sd.paket_nusantara * {$price_nusantara}) + 
            (sd.paket_kids * {$price_kids_meal}) +
            (sd.happy_bites * {$price_happy_bites}) +
            (sd.paket_royale * {$price_royale}) +
            (sd.paket_sweet_coffee * {$price_sweet_coffee}) +
            (sd.paket_matchamisu * {$price_matchamisu})
        ) as total_val
    FROM sales_data sd JOIN employees e ON sd.employee_id = e.id
    HAVING total_val > 0
    ORDER BY input_time DESC
");

if ($stmt_logs) {
    while ($row = $stmt_logs->fetch_assoc()) {
        $val = (float)$row['total_val'];
        $emp_val = $val * $employee_ratio;
        $omset_logs[] = [
            'date_time' => date('d/m/Y H:i:s', strtotime($row['input_time'])),
            'employee_name' => $row['employee_name'],
            'omset_kotor' => $val,
            'bagian_perusahaan' => $val * $company_ratio,
            'bagian_karyawan' => $emp_val
        ];
        if (!isset($member_summary[$row['employee_name']])) {
            $member_summary[$row['employee_name']] = ['total_omset' => 0, 'total_komisi' => 0, 'total_transaksi' => 0];
        }
        $member_summary[$row['employee_name']]['total_omset'] += $val;
        $member_summary[$row['employee_name']]['total_komisi'] += $emp_val;
        $member_summary[$row['employee_name']]['total_transaksi'] += 1;
    }
}

// MODIFIED: Changed to Dollar format
function formatCurrency($amount) { 
    return '$ ' . number_format($amount, 0, '.', ','); 
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pemasukan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .income-report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .income-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 15px; padding: 20px; text-align: center; box-shadow: var(--shadow-sm); }
        .income-card h4 { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.05em; }
        .income-card .value { font-size: 1.7rem; font-weight: 700; color: var(--primary-color); }
        .card-main { border-top: 4px solid var(--primary-color); }
        .card-net { border-top: 4px solid var(--success-color); }
        .card-salary { border-top: 4px solid var(--danger-color); }
        .section-box { background: var(--bg-card); border-radius: 15px; padding: 25px; margin-bottom: 30px; }
        .activities-table-improved { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.9rem; }
        .activities-table-improved th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border-color); color: var(--text-secondary); }
        .activities-table-improved td { padding: 12px; border-bottom: 1px solid var(--border-color); }
        .badge-komisi { background: #fef9c3; color: #854d0e; padding: 4px 8px; border-radius: 5px; font-weight: bold; }
        .badge-perusahaan { background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>📈 Laporan Keuangan (Sistem 80/20)</h1>
                <p>Distribusi Pendapatan: 80% Kas Perusahaan | 20% Komisi Anggota</p>
            </div>

            <div class="income-report-grid">
                <div class="income-card">
                    <h4>🏢 Bagian Perusahaan (80%)</h4>
                    <p class="value" style="color: #3b82f6;"><?= formatCurrency($company_share_total) ?></p>
                </div>
                <div class="income-card">
                    <h4>🤝 Total Komisi Anggota (20%)</h4>
                    <p class="value" style="color: #f59e0b;"><?= formatCurrency($employee_commission_total) ?></p>
                </div>
                <div class="income-card card-main">
                    <h4>💰 TOTAL OMSET PENJUALAN (100%)</h4>
                    <p class="value" style="color: var(--primary-color);"><?= formatCurrency($overall_total_income) ?></p>
                </div>
            </div>

            <div class="income-report-grid">
                <div class="income-card card-salary">
                    <h4>💵 Total Pengeluaran Gaji</h4>
                    <p class="value" style="color: var(--danger-color);"><?= formatCurrency($total_payroll_expenditure) ?></p>
                    <p style="font-size: 0.75rem; color: var(--text-muted);">Gaji Duty ($200/jam) + Bonus Sales ($200/paket)</p>
                </div>
                <div class="income-card card-net">
                    <h4>📈 Profit Bersih (Net)</h4>
                    <p class="value" style="color: var(--success-color);"><?= formatCurrency($net_income) ?></p>
                    <p style="font-size: 0.75rem; color: var(--text-muted);">(80% Omset) - (Pengeluaran Gaji)</p>
                </div>
            </div>

            <div class="section-box">
                <h3 style="text-align: center; margin-bottom: 20px;">Grafik Pendapatan Kas Perusahaan (Harian)</h3>
                <div style="height: 350px;"><canvas id="dailyRevenueChart"></canvas></div>
            </div>

            <div class="section-box">
                <h3 style="color: var(--primary-color);">👥 Ringkasan Komisi per Anggota</h3>
                <div class="responsive-table-container">
                    <table class="activities-table-improved">
                        <thead>
                            <tr>
                                <th>Nama Anggota</th>
                                <th>Total Transaksi</th>
                                <th>Total Penjualan (100%)</th>
                                <th>🏦 Hak Komisi (20%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_summary as $name => $data): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($name) ?></strong></td>
                                <td><?= $data['total_transaksi'] ?> Transaksi</td>
                                <td><?= formatCurrency($data['total_omset']) ?></td>
                                <td><span class="badge-komisi"><?= formatCurrency($data['total_komisi']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-box">
                <h3>📜 Logs Detail Transaksi</h3>
                <div class="responsive-table-container">
                    <table class="activities-table-improved">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Penjual</th>
                                <th>Omset Kotor</th>
                                <th>🏢 Kas Perusahaan</th>
                                <th>🏦 Komisi Anggota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($omset_logs as $log): ?>
                            <tr>
                                <td><?= $log['date_time'] ?></td>
                                <td><?= htmlspecialchars($log['employee_name']) ?></td>
                                <td><?= formatCurrency($log['omset_kotor']) ?></td>
                                <td><span class="badge-perusahaan"><?= formatCurrency($log['bagian_perusahaan']) ?></span></td>
                                <td><span class="badge-komisi"><?= formatCurrency($log['bagian_karyawan']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('dailyRevenueChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [{
                        label: 'Masuk Kas ($)',
                        data: <?= json_encode($chart_data_revenue) ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: '#2563eb',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        });
    </script>
</body>
</html>