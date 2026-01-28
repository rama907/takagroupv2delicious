<?php
// File: daily-duty-recap-cron.php
// Skrip ini dirancang untuk dijalankan melalui Cron Job setiap hari.
// Fungsinya: Mengirim rekap total jam duty semua anggota yang memiliki jam completed.

// Pastikan skrip hanya bisa diakses dari CLI atau Cron Job untuk keamanan
if (PHP_SAPI !== 'cli' && !isset($_SERVER['HTTP_USER_AGENT'])) {
    http_response_code(403);
    die("Access denied. This script is for CLI/Cron access only.");
}

// Memuat konfigurasi dan fungsi
require_once 'config.php';

// --- A. Mengambil Data Total Jam Duty dan Nama Karyawan ---
$duty_recap = [];
$stmt = $conn->query("
    SELECT
        e.name,
        COALESCE(SUM(dl.duration_minutes), 0) as total_duty_minutes
    FROM employees e
    JOIN duty_logs dl ON e.id = dl.employee_id
    WHERE dl.status = 'completed' AND e.status = 'active'
    GROUP BY e.id, e.name
    HAVING total_duty_minutes > 0 
    ORDER BY total_duty_minutes DESC
");

if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $duty_recap[] = [
            'name' => htmlspecialchars($row['name']),
            'duration' => formatDuration((int)$row['total_duty_minutes'])
        ];
    }
    $stmt->close();
} else {
    // Jika query gagal (misalnya, tabel belum ada)
    error_log("Error executing duty recap query: " . $conn->error);
}

// --- B. Kirim Notifikasi Discord ---
// Menggunakan tipe 'daily_duty_recap' yang baru didefinisikan di config.php
sendDiscordNotification([
    'duty_list' => $duty_recap
], 'daily_duty_recap');


// Tampilkan pesan sukses untuk log Cron Job
echo "Daily Duty Recap check completed successfully. Sent report for " . count($duty_recap) . " employees.\n";
?>