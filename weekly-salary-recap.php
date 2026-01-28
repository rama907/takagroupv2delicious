<?php
require_once 'config.php';

// Batasi akses: Wakil Direktur ke atas
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}

// Inisialisasi variabel filter dan sorting
$filter_role = $_GET['role'] ?? '';
$filter_date = $_GET['backup_date'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'week_start';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Pastikan sort_order valid
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Kumpulan role yang mungkin (asumsi diambil dari suatu fungsi atau database)
// Menggunakan array statis untuk contoh
$available_roles = [
    'ceo' => 'CEO',
    'direktur' => 'Direktur',
    'wakil_direktur' => 'Wakil Direktur',
    'manager' => 'Manajer',
    'chef' => 'Chef',
    'barista' => 'Barista',
    'karyawan' => 'Karyawan',
    'magang' => 'Magang',
    // Tambahkan role lain yang relevan
];

// Ambil semua data backup
// Siapkan query dengan filtering dan sorting
$sql = "
    SELECT 
        w.*,
        e.role as employee_role
    FROM weekly_salary_backup w
    JOIN employees e ON w.employee_id = e.id
    WHERE 1=1
";

$params = [];
$types = '';

// Filter Jabatan (Role)
if (!empty($filter_role)) {
    $sql .= " AND e.role = ?";
    $types .= 's';
    $params[] = $filter_role;
}

// Filter Tanggal Backup
if (!empty($filter_date)) {
    // Cari data yang di-backup pada tanggal tertentu
    $sql .= " AND DATE(w.backup_date) = ?";
    $types .= 's';
    $params[] = $filter_date;
}

// Sorting
// Pastikan kolom sorting valid untuk menghindari SQL Injection
$allowed_sorts = ['week_start', 'employee_name', 'employee_role', 'total_net_salary', 'backup_date'];
if (in_array($sort_by, $allowed_sorts)) {
    // Kolom sorting diambil dari variabel
    $sql .= " ORDER BY {$sort_by} {$sort_order}, w.week_start DESC, w.employee_name ASC";
} else {
    // Default sorting
    $sql .= " ORDER BY w.week_start DESC, w.employee_name ASC";
}


// Eksekusi query
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $backup_result = $stmt->get_result();
    $backup_data = $backup_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $backup_data = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}


// Fungsi untuk membantu menentukan class sorting
function getSortClass($column, $current_sort_by, $current_sort_order) {
    if ($column === $current_sort_by) {
        return $current_sort_order === 'ASC' ? 'sorted-asc' : 'sorted-desc';
    }
    return '';
}

// Fungsi untuk mendapatkan URL sorting baru
function getSortUrl($column, $current_sort_by, $current_sort_order, $filter_role, $filter_date) {
    $new_order = 'ASC';
    if ($column === $current_sort_by && $current_sort_order === 'ASC') {
        $new_order = 'DESC';
    }
    $query = http_build_query([
        'role' => $filter_role,
        'backup_date' => $filter_date,
        'sort_by' => $column,
        'sort_order' => $new_order
    ]);
    return '?' . $query;
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji Mingguan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .paid-status {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .status-Pending { background-color: #ffcc00; color: #333; }
        .status-Paid { background-color: #4CAF50; color: white; }
        .status-Error { background-color: #f44336; color: white; }
        .salary-table th, .salary-table td {
            white-space: nowrap;
        }
        .duty-time {
            color: var(--primary-color);
            font-weight: 600;
        }
        /* Style untuk sorting */
        .sortable {
            cursor: pointer;
            position: relative;
        }
        .sortable:hover {
            color: var(--primary-color);
        }
        .sortable::after {
            content: ' ';
            font-size: 0.7em;
            margin-left: 5px;
            opacity: 0.3;
        }
        .sortable.sorted-asc::after {
            content: '▲';
            opacity: 1;
            color: var(--primary-color);
        }
        .sortable.sorted-desc::after {
            content: '▼';
            opacity: 1;
            color: var(--primary-color);
        }
        /* Style untuk Filter Form */
        .filter-form {
            display: flex;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            margin-bottom: var(--spacing-sm);
            font-weight: 600;
        }
        .filter-form select, .filter-form input[type="date"], .filter-form button {
            padding: var(--spacing-sm) var(--spacing-md);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        .filter-form button {
            background-color: var(--primary-color);
            color: white;
            cursor: pointer;
            border: none;
            transition: background-color 0.2s;
        }
        .filter-form button:hover {
            background-color: var(--primary-color-dark);
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
                    <span class="page-icon">🐀򄸏</span>
                    Rekap Gaji Mingguan
                </h1>
                <p>Data backup nominal gaji anggota per minggu (diambil setiap Senin pukul 12.00).</p>
                <div class="info-message" style="margin-top: var(--spacing-lg);">
                    <strong>Jadwal Backup Otomatis:</strong> Setiap Hari Senin, Pukul 12:00 WIB.
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Riwayat Backup Gaji</h3>
                </div>
                <div class="card-content">
                    <form method="GET" class="filter-form">
                        <div class="filter-group">
                            <label for="role_filter">Filter Jabatan</label>
                            <select name="role" id="role_filter">
                                <option value="">-- Semua Jabatan --</option>
                                <?php foreach ($available_roles as $role_key => $role_name): ?>
                                    <option value="<?= $role_key ?>" <?= $filter_role === $role_key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($role_name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="backup_date_filter">Filter Tanggal Backup</label>
                            <input type="date" name="backup_date" id="backup_date_filter" value="<?= htmlspecialchars($filter_date) ?>">
                        </div>
                        <div class="filter-group">
                            <button type="submit">Terapkan Filter</button>
                        </div>
                        <?php if (!empty($filter_role) || !empty($filter_date)): ?>
                            <div class="filter-group">
                                <a href="weekly-salary-recap.php" class="button secondary-button" style="align-self: flex-end;">Reset Filter</a>
                            </div>
                        <?php endif; ?>
                        <?php if (in_array($sort_by, $allowed_sorts) && !empty($sort_by)): ?>
                            <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
                            <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
                        <?php endif; ?>
                    </form>

                    <?php if (empty($backup_data)): ?>
                        <div class="no-data">Belum ada data gaji mingguan yang di-backup sesuai kriteria filter.</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved salary-table">
                                <thead>
                                    <tr>
                                        <th class="sortable <?= getSortClass('week_start', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('week_start', $sort_by, $sort_order, $filter_role, $filter_date) ?>">
                                                Periode Minggu
                                            </a>
                                        </th>
                                        <th class="sortable <?= getSortClass('employee_name', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('employee_name', $sort_by, $sort_order, $filter_role, $filter_date) ?>">
                                                Nama Anggota
                                            </a>
                                        </th>
                                        <th class="sortable <?= getSortClass('employee_role', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('employee_role', $sort_by, $sort_order, $filter_role, $filter_date) ?>">
                                                Jabatan
                                            </a>
                                        </th>
                                        <th>Jam Duty (Asli)</th>
                                        <th>Jam Duty (Bulat)</th>
                                        <th>Base Gaji (100%)</th>
                                        <th class="sortable <?= getSortClass('total_net_salary', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('total_net_salary', $sort_by, $sort_order, $filter_role, $filter_date) ?>">
                                                Total Gaji (Net)
                                            </a>
                                        </th>
                                        <th>Status Bayar</th>
                                        <th class="sortable <?= getSortClass('backup_date', $sort_by, $sort_order) ?>">
                                            <a href="<?= getSortUrl('backup_date', $sort_by, $sort_order, $filter_role, $filter_date) ?>">
                                                Tanggal Backup
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backup_data as $data): ?>
                                    <tr>
                                        <td data-label="Periode Minggu">
                                            <?= date('d/m', strtotime($data['week_start'])) ?> - <?= date('d/m/Y', strtotime($data['week_end'])) ?>
                                        </td>
                                        <td data-label="Nama Anggota"><?= htmlspecialchars($data['employee_name']) ?></td>
                                        <td data-label="Jabatan"><?= getRoleDisplayName($data['employee_role']) ?></td>
                                        <td data-label="Jam Duty (Asli)">
                                            <?= formatDuration($data['duty_minutes_actual']) ?>
                                        </td>
                                        <td data-label="Jam Duty (Bulat)" class="duty-time">
                                            <?= formatDuration($data['duty_minutes_rounded']) ?>
                                        </td>
                                        <td data-label="Base Gaji (100%)"><?= formatRupiah($data['base_salary_nominal']) ?></td>
                                        <td data-label="Total Gaji (Net)"><strong><?= formatRupiah($data['total_net_salary']) ?></strong></td>
                                        <td data-label="Status Bayar">
                                            <span class="paid-status status-<?= htmlspecialchars($data['payment_status']) ?>">
                                                <?= htmlspecialchars($data['payment_status']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Tanggal Backup"><?= date('d/m/Y H:i', strtotime($data['backup_date'])) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>