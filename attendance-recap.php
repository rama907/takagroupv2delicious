<?php
require_once 'config.php';

// Hanya direktur, wakil_direktur, dan manager yang bisa mengakses halaman ini
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

global $conn; // Mengakses koneksi database global

// --- Logika Pengambilan Data Absensi ---

// Tentukan periode rekap (minggu berjalan: dari Senin minggu ini hingga hari ini)
$start_date_obj = new DateTime('this week monday'); // Mulai dari Senin minggu ini
$end_date_obj = new DateTime('today');   // Sampai hari ini

// Pastikan tanggal mulai tidak lebih besar dari tanggal akhir (jika terjadi kesalahan logika tanggal)
if ($start_date_obj > $end_date_obj) {
    $start_date_obj = clone $end_date_obj; // Setel tanggal mulai sama dengan tanggal akhir
}

// Perbaikan untuk Notice: Only variables should be passed by reference pada DatePeriod
$end_date_for_period = clone $end_date_obj; 
$end_date_for_period->modify('+1 day'); 

$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($start_date_obj, $interval, $end_date_for_period);

$dates_in_period = [];
foreach ($period as $dt) {
    $dates_in_period[] = $dt->format('Y-m-d');
}

// Mengambil semua anggota aktif
$stmt_employees = $conn->query("SELECT id, name FROM employees WHERE status = 'active' ORDER BY name");
$employees = $stmt_employees->fetch_all(MYSQLI_ASSOC);

$attendance_data = [];

// Mengambil semua log duty yang completed untuk periode ini
$duty_logs_by_employee_date = [];
$stmt_duty = $conn->prepare("SELECT employee_id, DATE(duty_start) as duty_date FROM duty_logs WHERE duty_start >= ? AND duty_start <= ? AND status = 'completed'");
$stmt_duty->bind_param("ss", $start_date_obj->format('Y-m-d 00:00:00'), $end_date_obj->format('Y-m-d 23:59:59'));
$stmt_duty->execute();
$result_duty = $stmt_duty->get_result();

// Perbaikan untuk Notice: Only variables should be passed by reference (Baris 47 di versi sebelumnya)
if ($result_duty instanceof mysqli_result) { // Pastikan $result_duty adalah objek mysqli_result
    $row_duty = $result_duty->fetch_assoc(); // Ambil baris pertama
    while ($row_duty !== null) { // Loop selama ada baris
        $duty_logs_by_employee_date[$row_duty['employee_id']][$row_duty['duty_date']] = true;
        $row_duty = $result_duty->fetch_assoc(); // Ambil baris berikutnya
    }
    $result_duty->free(); // Bebaskan hasil query
}
$stmt_duty->close();


// Mengambil semua permohonan cuti yang disetujui untuk periode ini
$leave_requests_by_employee = [];
$stmt_leave = $conn->prepare("SELECT employee_id, start_date, end_date FROM leave_requests WHERE (start_date <= ? AND end_date >= ?) AND status = 'approved'");
$stmt_leave->bind_param("ss", $end_date_obj->format('Y-m-d'), $start_date_obj->format('Y-m-d'));
$stmt_leave->execute();
$result_leave = $stmt_leave->get_result();

// Perbaikan untuk Notice: Only variables should be passed by reference (Baris 62 di versi sebelumnya)
if ($result_leave instanceof mysqli_result) { // Pastikan $result_leave adalah objek mysqli_result
    $row_leave = $result_leave->fetch_assoc(); // Ambil baris pertama
    while ($row_leave !== null) { // Loop selama ada baris
        $leave_requests_by_employee[$row_leave['employee_id']][] = [
            'start' => new DateTime($row_leave['start_date']),
            'end' => new DateTime($row_leave['end_date'])
        ];
        $row_leave = $result_leave->fetch_assoc(); // Ambil baris berikutnya
    }
    $result_leave->free(); // Bebaskan hasil query
}
$stmt_leave->close();


// Proses data absensi per karyawan per hari
foreach ($employees as $employee) {
    $employee_id = $employee['id'];
    $daily_statuses = [];
    $max_consecutive_absent = 0; // Maksimum absen beruntun dalam periode ini
    $current_consecutive_absent = 0; // Penghitung absen beruntun saat ini

    foreach ($dates_in_period as $date_str) {
        $status = 'Absen'; // Default status
        $current_day_obj = new DateTime($date_str);

        // 1. Cek status "Izin" (cuti yang disetujui)
        if (isset($leave_requests_by_employee[$employee_id])) {
            foreach ($leave_requests_by_employee[$employee_id] as $leave) {
                if ($current_day_obj >= $leave['start'] && $current_day_obj <= $leave['end']) {
                    $status = 'Izin';
                    break;
                }
            }
        }

        // 2. Cek status "Masuk" (ada jam duty) jika belum "Izin"
        if ($status === 'Absen') { // Jika belum dihitung sebagai izin
            if (isset($duty_logs_by_employee_date[$employee_id][$date_str])) {
                $status = 'Masuk';
            }
        }
        
        // Hitung absen beruntun
        if ($status === 'Absen') {
            $current_consecutive_absent++;
        } else {
            $current_consecutive_absent = 0; // Reset jika tidak absen
        }

        if ($current_consecutive_absent > $max_consecutive_absent) {
            $max_consecutive_absent = $current_consecutive_absent;
        }

        $daily_statuses[$date_str] = $status;
    }

    $attendance_data[] = [
        'employee_id' => $employee_id,
        'employee_name' => htmlspecialchars($employee['name']),
        'daily_statuses' => $daily_statuses,
        'max_consecutive_absent' => $max_consecutive_absent
    ];
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Gaya khusus untuk rekap absensi */
        .attendance-table-container {
            overflow-x: auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
            margin-top: var(--spacing-xl);
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px; /* Lebar minimum tabel untuk scroll */
        }

        .attendance-table th,
        .attendance-table td {
            padding: var(--spacing-sm);
            text-align: center;
            border: 1px solid var(--border-light);
            white-space: nowrap; /* Cegah teks putus baris */
        }

        .attendance-table th {
            background-color: var(--bg-tertiary);
            font-weight: 700;
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .attendance-table td {
            font-size: 0.875rem;
            color: var(--text-primary);
        }

        .attendance-table tbody tr:hover {
            background: var(--bg-secondary);
        }

        .attendance-table .employee-name-cell {
            text-align: left;
            font-weight: 600;
            position: sticky;
            left: 0;
            background-color: var(--bg-card); /* Menjaga latar belakang saat scroll */
            z-index: 10; /* Pastikan di atas sel lain saat scroll */
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }
        .attendance-table tbody tr:hover .employee-name-cell {
            background-color: var(--bg-secondary); /* Sesuaikan saat hover */
        }

        .status-cell {
            font-weight: 700;
            color: white;
            border-radius: var(--radius-sm);
            padding: 0.3em 0.6em;
            display: inline-block; /* Agar padding dan border radius bekerja */
            line-height: 1; /* Kontrol tinggi baris */
        }

        .status-Masuk {
            background-color: var(--success-color);
        }
        .status-Izin {
            background-color: var(--warning-color);
        }
        .status-Absen {
            background-color: var(--danger-color);
        }

        .consecutive-absent-cell {
            font-weight: 700;
            color: var(--danger-color);
        }
        .consecutive-absent-cell.green {
             color: var(--success-color);
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
                    <span class="page-icon">📅</span>
                    Rekap Absensi Anggota
                </h1>
                <p>Ikhtisar status kehadiran anggota untuk periode
                    **<?= $start_date_obj->format('d M Y') ?>** hingga
                    **<?= $end_date_obj->format('d M Y') ?>**.</p>
            </div>
            
            <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                <strong>💡 Info:</strong> Tanda seru `!` di sebelah nama menunjukkan anggota memiliki lebih dari 3 hari absen beruntun dalam periode ini.
            </div>

            <div class="attendance-table-container">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th class="employee-name-cell">Anggota</th>
                            <?php foreach ($dates_in_period as $date_str): ?>
                                <th><?= date('d/m', strtotime($date_str)) ?></th>
                            <?php endforeach; ?>
                            <th>Maks. Absen Beruntun</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="<?= count($dates_in_period) + 2 ?>" class="no-data">Tidak ada anggota aktif untuk ditampilkan.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attendance_data as $data): ?>
                                <tr>
                                    <td class="employee-name-cell <?= $data['max_consecutive_absent'] > 3 ? 'long-absent-warning' : '' ?>">
                                        <?= htmlspecialchars($data['employee_name']) ?>
                                        <?php if ($data['max_consecutive_absent'] > 3): ?>
                                            <span class="long-absent-alert">!</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($data['daily_statuses'] as $date_str => $status): ?>
                                        <td>
                                            <span class="status-cell status-<?= $status ?>">
                                                <?= substr($status, 0, 1) ?>
                                            </span>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="consecutive-absent-cell <?= $data['max_consecutive_absent'] == 0 ? 'green' : '' ?>">
                                         <?= $data['max_consecutive_absent'] ?> hari
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>