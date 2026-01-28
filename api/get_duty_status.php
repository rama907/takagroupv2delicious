<?php
// File: api/get_duty_status.php
// Mengembalikan jumlah karyawan On Duty dan total karyawan aktif.

header('Content-Type: application/json');
require_once '../config.php'; 

// Fungsi utilitas untuk respons JSON
function send_json_response($on_duty, $total_active) {
    echo json_encode([
        'status' => 'success',
        'on_duty_count' => (int)$on_duty,
        'total_active_count' => (int)$total_active
    ]);
    exit;
}

// Menggunakan koneksi $conn dari config.php

// 1. Hitung total karyawan aktif
$total_active_count = 0;
$result_total = $conn->query("SELECT COUNT(id) as total FROM employees WHERE status = 'active'");
if ($result_total) {
    $total_active_count = $result_total->fetch_assoc()['total'];
}

// 2. Hitung total karyawan yang sedang On Duty
$on_duty_count = 0;
$result_on_duty = $conn->query("SELECT COUNT(id) as on_duty FROM employees WHERE status = 'active' AND is_on_duty = TRUE");
if ($result_on_duty) {
    $on_duty_count = $result_on_duty->fetch_assoc()['on_duty'];
}

// Kirim respons
send_json_response($on_duty_count, $total_active_count);
?>