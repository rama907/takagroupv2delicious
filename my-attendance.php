<?php
require_once 'config.php';

// Redirect ke halaman login jika belum login
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser(); // Mengambil data pengguna yang sedang login
$pending_requests_count = getPendingRequestCount(); // Untuk indikator sidebar

global $conn; // Mengakses koneksi database global

// --- Logika Pengambilan Data Absensi Mingguan untuk Pengguna Ini ---
$weekly_attendance_data = [];
$employee_id = $user['id']; // Fokus pada pengguna yang sedang login

// Tentukan periode rekap (minggu berjalan: dari Senin minggu ini hingga hari ini)
$start_date_obj_week = new DateTime('this week monday');
$end_date_obj_week = new DateTime('today');

// Buat periode iterasi harian, termasuk tanggal akhir
$end_date_for_period_week = clone $end_date_obj_week;
$end_date_for_period_week->modify('+1 day'); 
$interval_week = DateInterval::createFromDateString('1 day');
$period_week = new DatePeriod($start_date_obj_week, $interval_week, $end_date_for_period_week);

$dates_in_week = [];
foreach ($period_week as $dt) {
    $dates_in_week[] = $dt->format('Y-m-d');
}

// Mengambil log duty yang completed untuk periode ini (hanya untuk user yang login)
$duty_logs_by_date = [];
$stmt_duty_week = $conn->prepare("SELECT DATE(duty_start) as duty_date FROM duty_logs WHERE employee_id = ? AND duty_start >= ? AND duty_start <= ? AND status = 'completed'");
$stmt_duty_week->bind_param("iss", $employee_id, $start_date_obj_week->format('Y-m-d 00:00:00'), $end_date_obj_week->format('Y-m-d 23:59:59'));
$stmt_duty_week->execute();
$result_duty_week = $stmt_duty_week->get_result();
if ($result_duty_week instanceof mysqli_result) {
    while ($row = $result_duty_week->fetch_assoc()) {
        $duty_logs_by_date[$row['duty_date']] = true;
    }
    $result_duty_week->free();
}
$stmt_duty_week->close();

// Mengambil permohonan cuti yang disetujui untuk periode ini (hanya untuk user yang login)
$leave_requests = [];
$stmt_leave_week = $conn->prepare("SELECT start_date, end_date FROM leave_requests WHERE employee_id = ? AND (start_date <= ? AND end_date >= ?) AND status = 'approved'");
$stmt_leave_week->bind_param("iss", $employee_id, $end_date_obj_week->format('Y-m-d'), $start_date_obj_week->format('Y-m-d'));
$stmt_leave_week->execute();
$result_leave_week = $stmt_leave_week->get_result();
if ($result_leave_week instanceof mysqli_result) {
    while ($row = $result_leave_week->fetch_assoc()) {
        $leave_requests[] = [
            'start' => new DateTime($row['start_date']),
            'end' => new DateTime($row['end_date'])
        ];
    }
    $result_leave_week->free();
}
$stmt_leave_week->close();

// Inisialisasi penghitung absen beruntun
$max_consecutive_absent = 0;
$current_consecutive_absent = 0;

// Proses status absensi per hari untuk minggu ini
foreach ($dates_in_week as $date_str) {
    $status = 'Absen'; // Default status
    $current_day_obj = new DateTime($date_str);

    // 1. Cek status "Izin" (cuti yang disetujui)
    foreach ($leave_requests as $leave) {
        if ($current_day_obj >= $leave['start'] && $current_day_obj <= $leave['end']) {
            $status = 'Izin';
            break;
        }
    }

    // 2. Cek status "Masuk" (ada jam duty) jika belum "Izin"
    if ($status === 'Absen') { 
        if (isset($duty_logs_by_date[$date_str])) {
            $status = 'Masuk';
        }
    }
    $weekly_attendance_data[$date_str] = $status;

    // Hitung absen beruntun untuk notifikasi
    if ($status === 'Absen') {
        $current_consecutive_absent++;
    } else {
        $current_consecutive_absent = 0; // Reset jika tidak absen
    }

    if ($current_consecutive_absent > $max_consecutive_absent) {
        $max_consecutive_absent = $current_consecutive_absent;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Saya - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Gaya tambahan untuk halaman absensi pribadi */
        .my-attendance-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
            margin-top: var(--spacing-xl);
        }

        .my-attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Sesuaikan lebar kolom */
            gap: var(--spacing-md);
            margin-top: var(--spacing-lg);
        }

        .day-status-item {
            text-align: center;
            padding: var(--spacing-sm);
            border-radius: var(--radius-lg);
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
        }

        .day-status-item .status-indicator-circle {
            display: block;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin: 0 auto var(--spacing-xs);
            border: 2px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white; /* Warna ikon di dalam lingkaran */
        }

        .day-status-item.Masuk .status-indicator-circle {
            background-color: var(--success-color);
        }
        .day-status-item.Izin .status-indicator-circle {
            background-color: var(--warning-color);
        }
        .day-status-item.Absen .status-indicator-circle {
            background-color: var(--danger-color);
        }

        /* Gaya baru untuk label teks status */
        .day-status-item .status-text-label {
            font-size: 0.75rem; /* Ukuran font lebih kecil untuk label */
            margin-top: var(--spacing-xs); /* Jarak dari angka tanggal */
            font-weight: 700;
            text-transform: uppercase;
        }

        /* Pastikan teks label memiliki warna kontras dengan latar belakangnya */
        .day-status-item.Masuk .status-text-label { color: white; }
        .day-status-item.Izin .status-text-label { color: var(--text-primary); } /* Kuning mungkin lebih baik teks gelap */
        .day-status-item.Absen .status-text-label { color: white; }
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
                    Absensi Saya
                </h1>
                <p>Status kehadiran Anda untuk minggu ini, dari **<?= $start_date_obj_week->format('d M Y') ?>** hingga **<?= $end_date_obj_week->format('d M Y') ?>**.</p>
            </div>

            <?php if ($max_consecutive_absent >= 2): ?>
                <div class="warning-message" style="margin-bottom: var(--spacing-xl);">
                    <strong>Pemberitahuan Penting:</strong> Anda tercatat tidak hadir selama **<?= $max_consecutive_absent ?> hari kerja berturut-turut**. Untuk memastikan kelancaran operasional dan pencatatan yang akurat, mohon segera ajukan surat cuti resmi jika Anda berhalangan hadir karena alasan tertentu. Ajukan di <a href="leave-request.php">sini</a>.
                </div>
            <?php endif; ?>

            <div class="my-attendance-card">
                <div class="card-header">
                    <h3>Rekap Absensi Mingguan</h3>
                </div>
                <div class="card-content">
                    <div class="my-attendance-grid">
                        <?php foreach ($dates_in_week as $date_str): ?>
                            <?php
                            $day_name_id = [
                                'Mon' => 'Senin', 'Tue' => 'Selasa', 'Wed' => 'Rabu',
                                'Thu' => 'Kamis', 'Fri' => 'Jumat', 'Sat' => 'Sabtu', 'Sun' => 'Minggu'
                            ];
                            $day_short_name = date('D', strtotime($date_str));
                            $day_full_name = $day_name_id[$day_short_name] ?? $day_short_name;
                            $day_num = date('d', strtotime($date_str));
                            $status = $weekly_attendance_data[$date_str];
                            
                            $status_icon = '';
                            switch ($status) {
                                case 'Masuk': $status_icon = '✔️'; break;
                                case 'Izin':  $status_icon = '📝'; break;
                                case 'Absen': $status_icon = '❌'; break;
                            }
                            ?>
                            <div class="day-status-item <?= $status ?>">
                                <span class="status-indicator-circle"><?= $status_icon ?></span>
                                <?= $day_full_name ?><br>
                                <?= $day_num ?><br>
                                <span class="status-text-label"><?= $status ?></span> </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>