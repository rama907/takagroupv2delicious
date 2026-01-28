<?php
// File: api/get_my_status.php
// API Endpoint yang dipanggil oleh Discord Bot untuk fitur /statusku.

header('Content-Type: application/json');
require_once '../config.php'; 

// --- Utility Function ---
function send_json_response($status, $message, $data = []) {
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// --- 1. Ambil Data dan Kunci Rahasia ---
$input = file_get_contents('php://input');
$request_data = json_decode($input, true);

$secret_key = $request_data['api_key'] ?? null;
$discord_id = $request_data['discord_id'] ?? null; 

// --- 2. Autentikasi API Key ---
if ($secret_key !== API_SECRET_KEY) {
    send_json_response('error', 'Unauthorized: Invalid API Key.', ['code' => 401]);
}

if (empty($discord_id)) {
    send_json_response('error', 'Discord User ID is required.', ['code' => 400]);
}

// --- 3. Verifikasi Karyawan melalui Discord ID ---
$employee_data = getEmployeeByDiscordId($discord_id);

if (!$employee_data) {
    send_json_response('error', 'Employee not found or Discord ID not mapped in the database. Please contact HR.', ['code' => 404]);
}

$employee_id = $employee_data['id'];
$employee_name = $employee_data['name'];
$is_on_duty = (bool)$employee_data['is_on_duty'];
$duty_start_time = $employee_data['current_duty_start'] ?? null;

// --- 4. Ambil Total Jam Kerja Keseluruhan (Completed Logs) ---
$total_minutes = 0;
$stmt_duty = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
if ($stmt_duty) {
    $stmt_duty->bind_param("i", $employee_id);
    $stmt_duty->execute();
    $total_minutes = $stmt_duty->get_result()->fetch_assoc()['total_minutes'] ?? 0;
    $stmt_duty->close();
}
$total_duty_duration = formatDuration($total_minutes);

// --- 5. Ambil Total Penjualan Paket Keseluruhan (FILTERED) ---
// Menghitung Western (paket_sake) + Nusantara (paket_anggur_merah) + Kids Meal (paket_tuak)
$total_sales_packages = 0;
$stmt_sales = $conn->prepare("
    SELECT SUM(paket_sake) + SUM(paket_anggur_merah) + SUM(paket_tuak) AS total_sales
    FROM sales_data
    WHERE employee_id = ?
    AND (paket_spicy_1 + paket_spicy_2 + paket_spicy_3) = 0 /* FILTER OUT MASAK LOGS */
    AND (paket_sake + paket_anggur_merah + paket_tuak) > 0 /* ONLY INCLUDE SALES LOGS */
");
if ($stmt_sales) {
    $stmt_sales->bind_param("i", $employee_id);
    $stmt_sales->execute();
    $total_sales_packages = $stmt_sales->get_result()->fetch_assoc()['total_sales'] ?? 0;
    $stmt_sales->close();
}

// --- 6. Ambil Total Masak Paket Keseluruhan (NEW) ---
// Menghitung Western Prep (paket_spicy_1) + Nusantara Prep (paket_spicy_2) + Kids Meal Prep (paket_spicy_3)
$total_prep_packages = 0;
$stmt_prep = $conn->prepare("
    SELECT SUM(paket_spicy_1) + SUM(paket_spicy_2) + SUM(paket_spicy_3) AS total_prep
    FROM sales_data
    WHERE employee_id = ?
    AND (paket_sake + paket_anggur_merah + paket_tuak) = 0 /* FILTER OUT SALES LOGS */
    AND (paket_spicy_1 + paket_spicy_2 + paket_spicy_3) > 0 /* ONLY INCLUDE MASAK LOGS */
");
if ($stmt_prep) {
    $stmt_prep->bind_param("i", $employee_id);
    $stmt_prep->execute();
    $total_prep_packages = $stmt_prep->get_result()->fetch_assoc()['total_prep'] ?? 0;
    $stmt_prep->close();
}


// --- 7. Hitung Durasi On Duty Saat Ini (Jika aktif) ---
$current_duty_duration = 'N/A';
if ($is_on_duty && $duty_start_time) {
    $start = new DateTime($duty_start_time);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $start->getTimestamp();
    $current_duty_minutes = floor($diff / 60);
    $current_duty_duration = formatDuration($current_duty_minutes);
}


// --- 8. Kirim Respons ---
send_json_response('success', 'Status berhasil diambil.', [
    'employee_name' => $employee_name,
    'is_on_duty' => $is_on_duty,
    'current_duty_start' => $duty_start_time,
    'current_duty_duration' => $current_duty_duration,
    'total_duty_overall' => $total_duty_duration,
    'total_sales_overall' => $total_sales_packages,
    'total_prep_overall' => $total_prep_packages // Metrik Masak Baru
]);

?>