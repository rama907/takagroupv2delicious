<?php
// File: daily-absent-recap-cron.php
// Skrip ini dirancang untuk dijalankan melalui Cron Job setiap hari pada pukul 19:30 (atau jam lain yang ditentukan).
// Perintah ini memicu notifikasi rekap absensi harian ke Discord.

// Pastikan skrip hanya bisa diakses dari CLI atau Cron Job untuk keamanan
if (PHP_SAPI !== 'cli' && !isset($_SERVER['HTTP_USER_AGENT'])) {
    // Jika diakses melalui web browser, berikan pesan error
    http_response_code(403);
    die("Akses ditolak. Skrip ini hanya untuk akses CLI/Cron Job.");
}

// Memuat konfigurasi dan fungsi (memberikan akses ke $conn dan sendDiscordNotification)
require_once 'config.php';

// --- A. Tentukan Periode Rekap (Minggu Berjalan) ---
// Periode: Dari Senin minggu ini sampai Hari Ini
date_default_timezone_set('Asia/Jakarta'); // Pastikan zona waktu sesuai dengan server dan target
$start_date_obj = new DateTime('this week monday');
$end_date_obj = new DateTime('today');
$end_date_for_period = clone $end_date_obj; 
$end_date_for_period->modify('+1 day'); 
$interval = DateInterval::createFromDateString('1 day');
$period = new DatePeriod($start_date_obj, $interval, $end_date_for_period);

$dates_in_period = [];
foreach ($period as $dt) {
    $dates_in_period[] = $dt->format('Y-m-d');
}

// --- B. Ambil Data Karyawan, Duty Log, dan Cuti yang Disetujui ---
$employees = $conn->query("SELECT id, name FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Ambil semua duty log completed untuk periode ini
$duty_logs_by_employee_date = [];
$stmt_duty = $conn->prepare("SELECT employee_id, DATE(duty_start) as duty_date FROM duty_logs WHERE duty_start >= ? AND duty_start <= ? AND status = 'completed'");
$stmt_duty->bind_param("ss", $start_date_obj->format('Y-m-d 00:00:00'), $end_date_obj->format('Y-m-d 23:59:59'));
$stmt_duty->execute();
$result_duty = $stmt_duty->get_result();
while ($row_duty = $result_duty->fetch_assoc()) {
    $duty_logs_by_employee_date[$row_duty['employee_id']][$row_duty['duty_date']] = true;
}
$stmt_duty->close();

// Ambil semua permohonan cuti yang disetujui
$leave_requests_by_employee = [];
$stmt_leave = $conn->prepare("SELECT employee_id, start_date, end_date FROM leave_requests WHERE (start_date <= ? AND end_date >= ?) AND status = 'approved'");
$stmt_leave->bind_param("ss", $end_date_obj->format('Y-m-d'), $start_date_obj->format('Y-m-d'));
$stmt_leave->execute();
$result_leave = $stmt_leave->get_result();
while ($row_leave = $result_leave->fetch_assoc()) {
    $leave_requests_by_employee[$row_leave['employee_id']][] = [
        'start' => new DateTime($row_leave['start_date']),
        'end' => new DateTime($row_leave['end_date'])
    ];
}
$stmt_leave->close();

// --- C. Hitung Absensi Beruntun dan Kumpulkan Daftar Absen > 3 Hari ---
$long_absent_employees = [];

foreach ($employees as $employee) {
    $employee_id = $employee['id'];
    $current_consecutive_absent = 0;
    $max_consecutive_absent = 0;

    foreach ($dates_in_period as $date_str) {
        $status = 'Absen'; // Default status
        $current_day_obj = new DateTime($date_str);

        // 1. Cek status "Izin"
        if (isset($leave_requests_by_employee[$employee_id])) {
            foreach ($leave_requests_by_employee[$employee_id] as $leave) {
                if ($current_day_obj >= $leave['start'] && $current_day_obj <= $leave['end']) {
                    $status = 'Izin';
                    break;
                }
            }
        }

        // 2. Cek status "Masuk"
        if ($status === 'Absen' && isset($duty_logs_by_employee_date[$employee_id][$date_str])) {
            $status = 'Masuk';
        }
        
        // Hitung absen beruntun
        if ($status === 'Absen') {
            $current_consecutive_absent++;
        } else {
            $current_consecutive_absent = 0;
        }

        if ($current_consecutive_absent > $max_consecutive_absent) {
            $max_consecutive_absent = $current_consecutive_absent;
        }
    }
    
    // Kriteria pelaporan: Absen beruntun > 3 hari
    if ($max_consecutive_absent > 3) {
        $long_absent_employees[] = $employee['name'];
    }
}

// --- D. Kirim Notifikasi Discord ---
// Menggunakan tipe 'daily_absent_recap' yang baru didefinisikan di config.php
sendDiscordNotification([
    'absent_list' => $long_absent_employees
], 'daily_absent_recap');


// Tampilkan pesan sukses untuk log Cron Job
echo "Daily Absentee Recap check completed successfully. Sent report for " . count($long_absent_employees) . " long-absent employees.\n";
?>