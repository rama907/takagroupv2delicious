<?php
// File: employee-report.php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Hanya Admin/Manager yang boleh akses
if (!hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$pending_requests_count = getPendingRequestCount();

// Filter Tanggal & Karyawan
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 'all';

// Ambil daftar karyawan untuk filter
$employees = $conn->query("SELECT id, name FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// --- 1. Query Data Penjualan (Sales Data) ---
// Menggunakan tabel sales_data
$sales_query = "
    SELECT 
        e.name as employee_name,
        SUM(s.paket_western) as total_western,
        SUM(s.paket_nusantara) as total_nusantara,
        SUM(s.paket_kids) as total_kids,
        SUM(s.happy_bites) as total_happy_bites,
        SUM(s.paket_royale) as total_royale
    FROM sales_data s
    JOIN employees e ON s.employee_id = e.id
    WHERE s.date BETWEEN ? AND ?
";

// --- 2. Query Data Masak (Cooking Data) ---
// Menggunakan tabel cooking_data (TERPISAH)
$cooking_query = "
    SELECT 
        e.name as employee_name,
        SUM(c.paket_western) as total_western,
        SUM(c.paket_nusantara) as total_nusantara,
        SUM(c.paket_kids) as total_kids,
        SUM(c.happy_bites) as total_happy_bites,
        SUM(c.paket_royale) as total_royale
    FROM cooking_data c
    JOIN employees e ON c.employee_id = e.id
    WHERE c.date BETWEEN ? AND ?
";

// --- 3. Query Kehadiran ---
$attendance_query = "
    SELECT 
        e.name as employee_name,
        COUNT(d.id) as total_shifts,
        SUM(d.duration_minutes) as total_minutes
    FROM duty_logs d
    JOIN employees e ON d.employee_id = e.id
    WHERE DATE(d.duty_start) BETWEEN ? AND ? AND d.status = 'completed'
";

// Tambahkan filter karyawan jika dipilih
$params = [$start_date, $end_date];
$types = "ss";

if ($filter_employee_id !== 'all') {
    $sales_query .= " AND s.employee_id = ?";
    $cooking_query .= " AND c.employee_id = ?";
    $attendance_query .= " AND d.employee_id = ?";
    $params[] = $filter_employee_id;
    $types .= "i";
}

$sales_query .= " GROUP BY e.id";
$cooking_query .= " GROUP BY e.id";
$attendance_query .= " GROUP BY e.id";

// Eksekusi Sales
$stmt = $conn->prepare($sales_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Eksekusi Cooking
$stmt = $conn->prepare($cooking_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$cooking_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Eksekusi Attendance
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attendance_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- Pengolahan Data untuk Tampilan ---
// Kita gabungkan data berdasarkan Nama Karyawan
$report_data = [];

// Helper function untuk init array karyawan
function initEmployeeData(&$data, $name) {
    if (!isset($data[$name])) {
        $data[$name] = [
            'sales' => ['western' => 0, 'nusantara' => 0, 'kids' => 0, 'happy_bites' => 0, 'royale' => 0, 'total' => 0],
            'cooking' => ['western' => 0, 'nusantara' => 0, 'kids' => 0, 'happy_bites' => 0, 'royale' => 0, 'total' => 0],
            'attendance' => ['shifts' => 0, 'hours' => 0]
        ];
    }
}

// Proses Sales
$grand_total_sales = 0;
foreach ($sales_result as $row) {
    initEmployeeData($report_data, $row['employee_name']);
    $report_data[$row['employee_name']]['sales']['western'] = (int)$row['total_western'];
    $report_data[$row['employee_name']]['sales']['nusantara'] = (int)$row['total_nusantara'];
    $report_data[$row['employee_name']]['sales']['kids'] = (int)$row['total_kids'];
    $report_data[$row['employee_name']]['sales']['happy_bites'] = (int)$row['total_happy_bites'];
    $report_data[$row['employee_name']]['sales']['royale'] = (int)$row['total_royale'];
    
    $subtotal = $row['total_western'] + $row['total_nusantara'] + $row['total_kids'] + $row['total_happy_bites'] + $row['total_royale'];
    $report_data[$row['employee_name']]['sales']['total'] = $subtotal;
    $grand_total_sales += $subtotal;
}

// Proses Cooking
$grand_total_cooking = 0;
foreach ($cooking_result as $row) {
    initEmployeeData($report_data, $row['employee_name']);
    $report_data[$row['employee_name']]['cooking']['western'] = (int)$row['total_western'];
    $report_data[$row['employee_name']]['cooking']['nusantara'] = (int)$row['total_nusantara'];
    $report_data[$row['employee_name']]['cooking']['kids'] = (int)$row['total_kids'];
    $report_data[$row['employee_name']]['cooking']['happy_bites'] = (int)$row['total_happy_bites'];
    $report_data[$row['employee_name']]['cooking']['royale'] = (int)$row['total_royale'];

    $subtotal = $row['total_western'] + $row['total_nusantara'] + $row['total_kids'] + $row['total_happy_bites'] + $row['total_royale'];
    $report_data[$row['employee_name']]['cooking']['total'] = $subtotal;
    $grand_total_cooking += $subtotal;
}

// Proses Attendance
$grand_total_hours = 0;
foreach ($attendance_result as $row) {
    initEmployeeData($report_data, $row['employee_name']);
    $report_data[$row['employee_name']]['attendance']['shifts'] = (int)$row['total_shifts'];
    $hours = round((int)$row['total_minutes'] / 60, 1);
    $report_data[$row['employee_name']]['attendance']['hours'] = $hours;
    $grand_total_hours += $hours;
}

// --- Data untuk Chart (Agregat per Menu) ---
$chart_menu_labels = ['Western', 'Nusantara', 'Kids Meal', 'Happy Bites', 'Royale'];
$chart_sales_data = [0, 0, 0, 0, 0];
$chart_cooking_data = [0, 0, 0, 0, 0];

foreach ($report_data as $emp) {
    $chart_sales_data[0] += $emp['sales']['western'];
    $chart_sales_data[1] += $emp['sales']['nusantara'];
    $chart_sales_data[2] += $emp['sales']['kids'];
    $chart_sales_data[3] += $emp['sales']['happy_bites'];
    $chart_sales_data[4] += $emp['sales']['royale'];

    $chart_cooking_data[0] += $emp['cooking']['western'];
    $chart_cooking_data[1] += $emp['cooking']['nusantara'];
    $chart_cooking_data[2] += $emp['cooking']['kids'];
    $chart_cooking_data[3] += $emp['cooking']['happy_bites'];
    $chart_cooking_data[4] += $emp['cooking']['royale'];
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Kinerja Karyawan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-section {
            background: var(--bg-card);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            margin-bottom: var(--spacing-xl);
            display: flex;
            gap: var(--spacing-md);
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        .summary-card {
            background: var(--bg-card);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .summary-card h3 {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }
        .summary-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .chart-container {
            background: var(--bg-card);
            padding: var(--spacing-lg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            margin-bottom: var(--spacing-xl);
            height: 400px;
        }
        .report-table-container {
            overflow-x: auto;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .report-table th, .report-table td {
            padding: var(--spacing-sm) var(--spacing-md);
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .report-table th {
            background: var(--bg-secondary);
            font-weight: 600;
        }
        .report-table td:first-child {
            text-align: left;
            font-weight: 500;
        }
        .group-header {
            background-color: var(--primary-light) !important;
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📈</span>
                    Laporan Kinerja Karyawan
                </h1>
                <p>Analisis data penjualan, aktivitas masak, dan jam kerja.</p>
            </div>

            <form method="GET" class="filter-section">
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label for="start_date">Dari Tanggal</label>
                    <input type="date" name="start_date" id="start_date" class="form-input" value="<?= $start_date ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label for="end_date">Sampai Tanggal</label>
                    <input type="date" name="end_date" id="end_date" class="form-input" value="<?= $end_date ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0; flex: 1;">
                    <label for="employee_id">Karyawan</label>
                    <select name="employee_id" id="employee_id" class="form-select">
                        <option value="all">Semua Karyawan</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= ($filter_employee_id == $emp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Tampilkan Laporan</button>
            </form>

            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Total Penjualan (Paket)</h3>
                    <div class="value"><?= number_format($grand_total_sales) ?></div>
                </div>
                <div class="summary-card">
                    <h3>Total Masak (Paket)</h3>
                    <div class="value"><?= number_format($grand_total_cooking) ?></div>
                </div>
                <div class="summary-card">
                    <h3>Total Jam Kerja</h3>
                    <div class="value"><?= number_format($grand_total_hours, 1) ?></div>
                </div>
            </div>

            <div class="chart-container">
                <canvas id="performanceChart"></canvas>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Rincian Per Karyawan</h3>
                </div>
                <div class="card-content report-table-container">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Nama Karyawan</th>
                                <th colspan="6" class="group-header">Penjualan (Sales)</th>
                                <th colspan="6" class="group-header" style="background-color: #e3f2fd !important; color: #1565c0;">Masak (Cooking)</th>
                                <th rowspan="2">Jam Kerja</th>
                            </tr>
                            <tr>
                                <th>West</th>
                                <th>Nusa</th>
                                <th>Kids</th>
                                <th>Happy</th>
                                <th>Royale</th>
                                <th>Total</th>
                                
                                <th style="background-color: #f1f8e9; color: #000;">West</th>
                                <th style="background-color: #f1f8e9; color: #000;">Nusa</th>
                                <th style="background-color: #f1f8e9; color: #000;">Kids</th>
                                <th style="background-color: #f1f8e9; color: #000;">Happy</th>
                                <th style="background-color: #f1f8e9; color: #000;">Royale</th>
                                <th style="background-color: #f1f8e9; color: #000;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report_data)): ?>
                                <tr>
                                    <td colspan="14">Tidak ada data untuk periode ini.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report_data as $name => $data): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($name) ?></td>
                                        
                                        <td><?= $data['sales']['western'] ?></td>
                                        <td><?= $data['sales']['nusantara'] ?></td>
                                        <td><?= $data['sales']['kids'] ?></td>
                                        <td><?= $data['sales']['happy_bites'] ?></td>
                                        <td><?= $data['sales']['royale'] ?></td>
                                        <td><strong><?= $data['sales']['total'] ?></strong></td>
                                        
                                        <td style="background-color: #f9fbe7; color: #000;"><?= $data['cooking']['western'] ?></td>
                                        <td style="background-color: #f9fbe7; color: #000;"><?= $data['cooking']['nusantara'] ?></td>
                                        <td style="background-color: #f9fbe7; color: #000;"><?= $data['cooking']['kids'] ?></td>
                                        <td style="background-color: #f9fbe7; color: #000;"><?= $data['cooking']['happy_bites'] ?></td>
                                        <td style="background-color: #f9fbe7; color: #000;"><?= $data['cooking']['royale'] ?></td>
                                        <td style="background-color: #f9fbe7; color: #000;"><strong><?= $data['cooking']['total'] ?></strong></td>
                                        
                                        <td><?= $data['attendance']['hours'] ?> Jam</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Setup Chart
        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_menu_labels) ?>,
                datasets: [
                    {
                        label: 'Total Penjualan',
                        data: <?= json_encode($chart_sales_data) ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Total Masak',
                        data: <?= json_encode($chart_cooking_data) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Komparasi Total Penjualan vs Masak per Menu (Periode Terpilih)'
                    },
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Jumlah Paket'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>