<?php
// File: api/backup_weekly_salary.php
// Didesain untuk dijalankan oleh CRON/Task Scheduler setiap hari Senin pukul 12:00 siang.

// Fungsi utilitas untuk respons JSON
function send_json_response($status, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}


// Pastikan skrip hanya dapat diakses melalui CLI atau dengan kunci rahasia jika dijalankan melalui web.
if (php_sapi_name() !== 'cli' && (!isset($_GET['secret_key']) || $_GET['secret_key'] !== '903dfe9907e5d0cb375548911d3a4645')) {
    http_response_code(403);
    send_json_response('error', 'Akses ditolak.'); 
}

require_once '../config.php'; // Sesuaikan path

// --- DEFINISI GAJI (DISINKRONKAN DARI salary-recap.php) ---
const HOURLY_WAGE_RATES = [
    'ceo' => 40000,          
    'direktur' => 40000,     
    'wakil_direktur' => 40000, 
    'manager' => 24400,
    'guard' => 19200,
    'barista' => 19200,
    'waiters' => 14000,
    'karyawan' => 14000,
    'magang' => 9600,
    'chef' => 0, // Asumsi Chef masih tidak digaji per jam
];
const MIN_DUTY_FULL_PAY_HOURS = 10;
const MIN_DUTY_40_CUT_HOURS = 8; 

/**
 * Membulatkan total menit duty ke jam terdekat (X.5 ke atas).
 */
function roundToNearestHour($minutes) {
    return round($minutes / 60);
}
// --------------------------------------------------------


// 1. Tentukan periode minggu lalu (Selasa Minggu Lalu - Senin Kemarin)
// Tujuannya adalah mengambil data 7 hari penuh sebelum hari Selasa (hari CRON dijalankan).

// Tanggal akhir periode (Kemarin, 23:59:59) - Ini adalah HARI SENIN jika CRON jalan SELASA, atau MINGGU jika CRON jalan SENIN
$week_end = (new DateTime('yesterday'))->setTime(23, 59, 59); 

// Tanggal mulai periode (6 hari sebelum tanggal akhir, 00:00:00)
$week_start = (clone $week_end)->modify('-6 days')->setTime(0, 0, 0); 

$start_datetime = $week_start->format('Y-m-d H:i:s'); // Untuk filter DATETIME
$end_datetime = $week_end->format('Y-m-d H:i:s');

// Variabel yang akan diikat ke kolom DATE di database (format DATE saja)
$week_start_date = $week_start->format('Y-m-d');
$week_end_date = $week_end->format('Y-m-d');

$conn->begin_transaction();
$success_count = 0;
$log_messages = [];

try {
    // 2. Ambil data jam kerja AKTIF untuk periode minggu lalu
    $sql_duty = "
        SELECT 
            e.id as employee_id,
            e.name as employee_name,
            e.role as employee_role, 
            SUM(dl.duration_minutes) as duty_minutes_actual
        FROM employees e
        JOIN duty_logs dl ON e.id = dl.employee_id
        WHERE dl.status = 'completed' AND dl.duty_start BETWEEN ? AND ? 
        GROUP BY e.id, e.name, e.role
    ";
    
    $stmt_duty = $conn->prepare($sql_duty);
    if (!$stmt_duty) {
        throw new Exception("Prepare duty query failed: Cek nama kolom duty_start di duty_logs. MySQL Error: " . $conn->error);
    }
    // Bind parameter menggunakan tipe string (s) karena $start_datetime dan $end_datetime adalah string DATETIME
    $stmt_duty->bind_param("ss", $start_datetime, $end_datetime); 
    $stmt_duty->execute();
    $duty_results = $stmt_duty->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_duty->close();

    // 3. Ambil semua karyawan aktif (untuk backup 0 jam bagi yang tidak ada duty)
    $all_employees_raw = $conn->query("SELECT id, name, role, is_paid FROM employees WHERE status = 'active'");
    $all_employees = $all_employees_raw->fetch_all(MYSQLI_ASSOC);
    $duty_map = [];
    foreach ($duty_results as $row) {
        $duty_map[$row['employee_id']] = $row;
    }


    // 4. Proses dan simpan backup per karyawan
    $sql_insert = "
        INSERT INTO weekly_salary_backup 
        (employee_id, employee_name, week_start, week_end, duty_minutes_actual, duty_minutes_rounded, base_salary_nominal, total_net_salary, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            duty_minutes_actual = VALUES(duty_minutes_actual),
            duty_minutes_rounded = VALUES(duty_minutes_rounded),
            base_salary_nominal = VALUES(base_salary_nominal),
            total_net_salary = VALUES(total_net_salary),
            payment_status = VALUES(payment_status), /* Update payment_status jika sudah dibayar di employees */
            backup_date = NOW()
    ";

    $stmt_insert = $conn->prepare($sql_insert);
    if (!$stmt_insert) {
        throw new Exception("Prepare insert query failed: Cek tabel 'weekly_salary_backup'. MySQL Error: " . $conn->error);
    }
    
    foreach ($all_employees as $emp) {
        $employee_id = (int)$emp['id'];
        $employee_name = $emp['name'];
        $employee_role = $emp['role'];
        $is_paid_current = (bool)$emp['is_paid'];

        $actual_minutes = (int)($duty_map[$employee_id]['duty_minutes_actual'] ?? 0);
        
        // --- LOGIKA GAJI SESUAI salary-recap.php ---
        $rounded_duty_hours = roundToNearestHour($actual_minutes);
        $rounded_minutes = $rounded_duty_hours * 60; // Menit Bulat
        $hourly_rate = HOURLY_WAGE_RATES[$employee_role] ?? 0;

        $base_salary_nominal = $rounded_duty_hours * $hourly_rate;
        $total_gajian = 0; 

        if (in_array($employee_role, ['chef'])) {
            $total_gajian = 0;
        } elseif (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])) {
            $total_gajian = $base_salary_nominal;
        } else {
            if ($rounded_duty_hours >= MIN_DUTY_FULL_PAY_HOURS) {
                $total_gajian = $base_salary_nominal; // Full Pay
            } elseif ($rounded_duty_hours >= MIN_DUTY_40_CUT_HOURS) {
                $total_gajian = $base_salary_nominal * 0.60; // Potongan 40%
            } else {
                $total_gajian = $base_salary_nominal * 0.50; // Potongan 50%
            }
        }
        $total_net_salary = $total_gajian; 
        
        // Status pembayaran: Jika sudah dibayar di kolom 'employees', maka dianggap Paid untuk backup minggu ini.
        $payment_status_backup = $is_paid_current ? 'Paid' : 'Pending';
        // ------------------------------------------

        // FIX KRITIS: String tipe yang benar untuk 9 parameter: (i, s, s, s, i, i, d, d, s)
        $stmt_insert->bind_param("isssiidds", 
            $employee_id,           // i (employee_id)
            $employee_name,         // s (employee_name)
            $week_start_date,       // s (week_start)
            $week_end_date,         // s (week_end)
            $actual_minutes,        // i (duty_minutes_actual)
            $rounded_minutes,       // i (duty_minutes_rounded)
            $base_salary_nominal,   // d (base_salary_nominal)
            $total_net_salary,      // d (total_net_salary)
            $payment_status_backup  // s (payment_status)
        );
        $stmt_insert->execute();
        $success_count++;
    }

    $stmt_insert->close();
    $conn->commit();
    $log_messages[] = "SUCCESS: Berhasil membackup gaji untuk {$success_count} karyawan. Periode: {$week_start_date} hingga {$week_end_date}.";
    
} catch (Exception $e) {
    $conn->rollback();
    $log_messages[] = "ERROR: Gagal memproses backup. " . $e->getMessage();
    $log_messages[] = "DEBUG: Terjadi di line " . $e->getLine();

    // Pastikan headers belum dikirim sebelum memanggil send_json_response
    if (!headers_sent()) {
        send_json_response('log', implode(' | ', $log_messages));
    } else {
        echo implode("\n", $log_messages) . "\n";
    }
}

// Output log (penting untuk CRON)
if (php_sapi_name() === 'cli') {
    echo implode("\n", $log_messages) . "\n";
} else {
    // Output JSON untuk web
    send_json_response('log', implode(' | ', $log_messages));
}
?>