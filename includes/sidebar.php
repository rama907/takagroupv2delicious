<?php
// File: rama907/takagroup/rama907-takagroup-8ee4312790aa74e2b35b9ce31a1ab20b69583b9c/includes/sidebar.php

// Kode ini akan dieksekusi setiap kali sidebar dimuat
// Pastikan variabel $conn dan $user sudah tersedia dari file PHP utama yang memanggil sidebar.php

$max_consecutive_absent_sidebar = 0; // Inisialisasi penghitung absen beruntun untuk sidebar

// Lakukan perhitungan absensi hanya jika pengguna sudah login dan variabel $user serta $conn tersedia
if (isset($_SESSION['user_id']) && isset($conn) && isset($user)) {
    $employee_id_sidebar = $user['id'];

    // Tentukan periode rekap (minggu berjalan: dari Senin minggu ini hingga hari ini)
    $start_date_obj_sidebar = new DateTime('this week monday');
    $end_date_obj_sidebar = new DateTime('today');

    // Buat periode iterasi harian, termasuk tanggal akhir
    $end_date_for_period_sidebar = clone $end_date_obj_sidebar;
    $end_date_for_period_sidebar->modify('+1 day'); 
    $interval_sidebar = DateInterval::createFromDateString('1 day');
    $period_sidebar = new DatePeriod($start_date_obj_sidebar, $interval_sidebar, $end_date_for_period_sidebar);

    $dates_in_week_sidebar = [];
    foreach ($period_sidebar as $dt) {
        $dates_in_week_sidebar[] = $dt->format('Y-m-d');
    }

    // Mengambil log duty yang completed untuk periode ini (hanya untuk user yang login)
    $duty_logs_by_date_sidebar = [];
    $stmt_duty_sidebar = $conn->prepare("SELECT DATE(duty_start) as duty_date FROM duty_logs WHERE employee_id = ? AND duty_start >= ? AND duty_start <= ? AND status = 'completed'");
    if ($stmt_duty_sidebar) {
        $stmt_duty_sidebar->bind_param("iss", $employee_id_sidebar, $start_date_obj_sidebar->format('Y-m-d 00:00:00'), $end_date_obj_sidebar->format('Y-m-d 23:59:59'));
        $stmt_duty_sidebar->execute();
        $result_duty_sidebar = $stmt_duty_sidebar->get_result();
        if ($result_duty_sidebar instanceof mysqli_result) {
            while ($row = $result_duty_sidebar->fetch_assoc()) {
                $duty_logs_by_date_sidebar[$row['duty_date']] = true;
            }
            $result_duty_sidebar->free();
        }
        $stmt_duty_sidebar->close();
    }

    // Mengambil permohonan cuti yang disetujui untuk periode ini (hanya untuk user yang login)
    $leave_requests_sidebar = [];
    $stmt_leave_sidebar = $conn->prepare("SELECT start_date, end_date FROM leave_requests WHERE employee_id = ? AND (start_date <= ? AND end_date >= ?) AND status = 'approved'");
    if ($stmt_leave_sidebar) {
        $stmt_leave_sidebar->bind_param("iss", $employee_id_sidebar, $end_date_obj_sidebar->format('Y-m-d'), $start_date_obj_sidebar->format('Y-m-d'));
        $stmt_leave_sidebar->execute();
        $result_leave_sidebar = $stmt_leave_sidebar->get_result();
        if ($result_leave_sidebar instanceof mysqli_result) {
            while ($row = $result_leave_sidebar->fetch_assoc()) {
                $leave_requests_sidebar[] = [
                    'start' => new DateTime($row['start_date']),
                    'end' => new DateTime($row['end_date'])
                ];
            }
            $result_leave_sidebar->free();
        }
        $stmt_leave_sidebar->close();
    }

    $current_consecutive_absent_sidebar = 0;
    foreach ($dates_in_week_sidebar as $date_str) {
        $status_sidebar = 'Absen'; // Default status
        $current_day_obj_sidebar = new DateTime($date_str);

        // 1. Cek status "Izin" (cuti yang disetujui)
        foreach ($leave_requests_sidebar as $leave) {
            if ($current_day_obj_sidebar >= $leave['start'] && $current_day_obj_sidebar <= $leave['end']) {
                $status_sidebar = 'Izin';
                break;
            }
        }

        // 2. Cek status "Masuk" (ada jam duty) jika belum "Izin"
        if ($status_sidebar === 'Absen') { 
            if (isset($duty_logs_by_date_sidebar[$date_str])) {
                $status_sidebar = 'Masuk';
            }
        }

        // Hitung absen beruntun
        if ($status_sidebar === 'Absen') {
            $current_consecutive_absent_sidebar++;
        } else {
            $current_consecutive_absent_sidebar = 0;
        }

        if ($current_consecutive_absent_sidebar > $max_consecutive_absent_sidebar) {
            $max_consecutive_absent_sidebar = $current_consecutive_absent_sidebar;
        }
    }
    
    // BARU: Hitung jumlah surat peringatan untuk pengguna saat ini
    $my_warnings_count = 0;
    $stmt_my_warnings = $conn->prepare("SELECT COUNT(*) as total FROM warning_letters WHERE employee_id = ?");
    if ($stmt_my_warnings) {
        $stmt_my_warnings->bind_param("i", $employee_id_sidebar);
        $stmt_my_warnings->execute();
        $result_my_warnings = $stmt_my_warnings->get_result();
        $row_my_warnings = $result_my_warnings->fetch_assoc();
        $my_warnings_count = $row_my_warnings['total'];
        $stmt_my_warnings->close();
    }
}

// BARU: Ambil hitungan pending yang terpisah
// CATATAN: Karena fungsi getPendingRequestCounts() baru dibuat, 
// pastikan file config.php sudah diupdate dengan fungsi tersebut.
$all_pending_counts = getPendingRequestCounts();
$pending_requests_count = $all_pending_counts['employee_requests']; // Permohonan (Cuti, Resign, dll)
$pending_bookings_count = $all_pending_counts['booking_requests']; // Kelola Pemesanan
$total_pending_all = $all_pending_counts['total']; // Total semua pending (untuk menu "Semua Permohonan" jika perlu)

// Hitung total surat peringatan untuk notifikasi "Semua Surat Peringatan"
$all_warnings_count = 0;
if (isset($conn) && isLoggedIn()) { 
    $stmt_warnings_count = $conn->query("SELECT COUNT(*) as total FROM warning_letters");
    if ($stmt_warnings_count) {
        $result_warnings = $stmt_warnings_count->fetch_assoc();
        $all_warnings_count = $result_warnings['total'];
        $stmt_warnings_count->close();
    }
}
?>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-content">
        <a href="dashboard" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon">🏠</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="sales" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : '' ?>">
            <span class="nav-icon">💰</span>
            <span class="nav-text">Data Penjualan</span>
        </a>
        <a href="data-masak" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'data-masak.php' ? 'active' : '' ?>">
            <span class="nav-icon">🍳</span>
            <span class="nav-text">Data Masak</span>
        </a>
        <a href="refrigerator-stock" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'refrigerator-stock.php' ? 'active' : '' ?>">
            <span class="nav-icon">🍛</span>
            <span class="nav-text">Stok Kulkas</span>
        </a>    
        <a href="warehouse-stock" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'warehouse-stock.php' ? 'active' : '' ?>">
            <span class="nav-icon">📦</span>
            <span class="nav-text">Stok Gudang</span>
        </a>     
        <a href="manual-duty" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'manual-duty.php' ? 'active' : '' ?>">
            <span class="nav-icon">⏱️</span>
            <span class="nav-text">Input Manual</span>
        </a>
        <a href="activities" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'activities.php' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span class="nav-text">Aktivitas Saya</span>
        </a>
        <a href="my-payslip" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-payslip.php' ? 'active' : '' ?>">
            <span class="nav-icon">📄</span>
            <span class="nav-text">Slip Gaji Saya</span>
        </a>
        <a href="my-warnings" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-warnings.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚠️</span>
            <span class="nav-text">Surat Peringatan Saya</span>
            <?php if (isset($my_warnings_count) && $my_warnings_count > 0): ?>
                <span class="warning-indicator">!</span>
            <?php endif; ?>
        </a>
        <a href="my-attendance" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'my-attendance.php' ? 'active' : '' ?>">
            <span class="nav-icon">📅</span>
            <span class="nav-text">Absensi Saya</span>
            <?php if (isset($max_consecutive_absent_sidebar) && $max_consecutive_absent_sidebar >= 2): ?>
                <span class="absent-warning-indicator">!</span>
            <?php endif; ?>
        </a>
        
        <div class="nav-divider"></div>
        <a href="all-requests" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'all-requests.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Semua Permohonan</span>
        </a>
        <a href="all-warnings" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'all-warnings.php' ? 'active' : '' ?>">
            <span class="nav-icon">📜</span>
            <span class="nav-text">Semua Surat Peringatan</span>
            <?php if (isset($all_warnings_count) && $all_warnings_count > 0): ?>
                <span class="pending-indicator"><?= $all_warnings_count ?></span>
            <?php endif; ?>
        </a>
        <a href="suggestions" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'suggestions.php' ? 'active' : '' ?>">
            <span class="nav-icon">🚨</span>
            <span class="nav-text">Saran & Kritik (Anonim)</span>
        </a>
        <a href="change-password" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'change-password.php' ? 'active' : '' ?>">
            <span class="nav-icon">🔑</span>
            <span class="nav-text">Ubah Kata Sandi</span>
        </a>
        
        <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])): ?>
        <div class="nav-divider"></div>
        <a href="employee-report" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employee-report.php' ? 'active' : '' ?>">
            <span class="icon">🌎</span>
            <span class="text">Laporan Karyawan</span>
        </a>
        <a href="employees" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : '' ?>">
            <span class="nav-icon">👥</span>
            <span class="nav-text">Daftar Anggota</span>
        </a>
        <a href="employee-activities" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'employee-activities.php' ? 'active' : '' ?>">
            <span class="nav-icon">📈</span>
            <span class="nav-text">Aktivitas Anggota</span>
        </a>
        <a href="income-report" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'income-report.php' ? 'active' : '' ?>">
            <span class="nav-icon">💳</span>
            <span class="nav-text">Laporan Pemasukan</span>
        </a>
        <a href="salary-recap" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'salary-recap.php' ? 'active' : '' ?>">
            <span class="nav-icon">💸</span>
            <span class="nav-text">Rekap Gaji</span>
        </a>
        <a href="attendance-recap" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'attendance-recap.php' ? 'active' : '' ?>">
            <span class="nav-icon">📅</span>
            <span class="nav-text">Rekap Absensi</span>
        </a>
        <a href="warning-management" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'warning-management.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚠️</span>
            <span class="nav-text">Manajemen SP</span>
        </a>

        <a href="manage-bookings" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'manage-bookings.php' ? 'active' : '' ?>">
            <span class="nav-icon">🛎️</span>
            <span class="nav-text">Kelola Pemesanan</span>
            <?php if (isset($pending_bookings_count) && $pending_bookings_count > 0): ?>
                <span class="pending-indicator"><?= $pending_bookings_count ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur'])): ?>
        <div class="nav-divider"></div>

        <a href="requests" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Permohonan</span>
            <?php if (isset($pending_requests_count) && $pending_requests_count > 0): ?>
                <span class="pending-indicator"><?= $pending_requests_count ?></span>
            <?php endif; ?>
        </a>
        <a href="admin" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span>
            <span class="nav-text">Admin Panel</span>
        </a>
        <a href="duty-history-management" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'duty-history-management.php' ? 'active' : '' ?>">
            <span class="nav-icon">⏱️</span>
            <span class="nav-text">Manajemen Jam Duty</span>
        </a>
        <a href="weekly-salary-recap" class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'weekly-salary-recap.php' ? 'active' : '' ?>">
            <span class="nav-icon">📘</span>
            <span class="nav-text">Backup Gaji Mingguan</span>
        </a>
        <?php endif; ?>
    </div>
</nav>