<?php
// File: api/duty_action.php
// API Endpoint yang dipanggil oleh Discord Bot untuk Clock In/Out.

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
$action = strtolower($request_data['action'] ?? '');

// --- 2. Autentikasi API Key ---
if ($secret_key !== API_SECRET_KEY) {
    $security_check = (API_SECRET_KEY === 'MASUKKAN_KUNCI_RAHASIA_ANDA_DISINI') ? 
        'Error: Default API Key is still set in config.php. Please change it.' : 
        'Invalid API Key.';
        
    send_json_response('error', 'Unauthorized: ' . $security_check, ['code' => 401]);
}

if (empty($discord_id)) {
    send_json_response('error', 'Discord User ID is required.', ['code' => 400]);
}
if ($action !== 'clock_in' && $action !== 'clock_out') {
    send_json_response('error', 'Invalid action specified. Must be "clock_in" or "clock_out".', ['code' => 400]);
}

// --- 3. Verifikasi Karyawan melalui Discord ID ---
$employee_data = getEmployeeByDiscordId($discord_id);

if (!$employee_data) {
    send_json_response('error', 'Employee not found or Discord ID not mapped in the database. Please contact HR.', ['code' => 404]);
}

$employee_id = $employee_data['id'];
$employee_name = $employee_data['name'];
$is_on_duty = (bool)$employee_data['is_on_duty'];

$conn->begin_transaction();

try {
    if ($action === 'clock_in') {
        // --- LOGIC: CLOCK IN ---
        if ($is_on_duty) {
            send_json_response('warning', "{$employee_name} sudah terhitung On Duty.", ['state' => 'already_on']);
        }

        // 1. Update status employee
        $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
        $stmt_update_employee->bind_param("i", $employee_id);
        $stmt_update_employee->execute();
        $stmt_update_employee->close();

        // 2. Insert new duty log (is_manual=2 for Discord/Bot)
        $stmt_insert_log = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start, is_manual, status) VALUES (?, NOW(), 2, 'active')"); 
        if (!$stmt_insert_log) {
            throw new Exception("MySQL Insert Log Error: " . $conn->error);
        }
        $stmt_insert_log->bind_param("i", $employee_id);
        $stmt_insert_log->execute();
        $stmt_insert_log->close();
        
        $conn->commit();
        
        sendDiscordNotification(['employee_name' => $employee_name, 'event_type' => 'clock_in'], 'clock_event');

        send_json_response('success', "On Duty berhasil! Selamat bertugas, {$employee_name}.", ['state' => 'clocked_in']);

    } elseif ($action === 'clock_out') {
        // --- LOGIC: CLOCK OUT (CRITICAL FIX) ---
        if (!$is_on_duty) {
            send_json_response('warning', "{$employee_name} sudah terhitung Off Duty. Tidak ada shift aktif.", ['state' => 'already_off']);
        }

        // 1. Cari log duty aktif (Locking the row for update)
        $stmt_get_log = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE");
        if (!$stmt_get_log) {
            throw new Exception("MySQL Prepare Error (SELECT log): " . $conn->error);
        }
        $stmt_get_log->bind_param("i", $employee_id);
        $stmt_get_log->execute();
        $active_log = $stmt_get_log->get_result()->fetch_assoc();
        $stmt_get_log->close();

        if ($active_log) {
            // 2. Update log duty (set duty_end, calculate duration, set status completed)
            // FIX KRITIS: Menggunakan NOW() dan TIMESTAMPDIFF(MINUTE, ...)
            $stmt_update_log = $conn->prepare("
                UPDATE duty_logs 
                SET duty_end = NOW(), 
                    duration_minutes = TIMESTAMPDIFF(MINUTE, duty_start, NOW()), 
                    status = 'completed' 
                WHERE id = ?
            ");
            if (!$stmt_update_log) {
                 throw new Exception("MySQL Prepare Error (UPDATE log): " . $conn->error);
            }
            $stmt_update_log->bind_param("i", $active_log['id']);
            $stmt_update_log->execute();
            $stmt_update_log->close();
            
            // Re-fetch the duration to send correct response
            // Kita bisa re-fetch atau hitung ulang, re-fetch lebih aman
            $stmt_re_fetch = $conn->prepare("SELECT duration_minutes FROM duty_logs WHERE id = ?");
            $stmt_re_fetch->bind_param("i", $active_log['id']);
            $stmt_re_fetch->execute();
            $final_duration = $stmt_re_fetch->get_result()->fetch_assoc();
            $stmt_re_fetch->close();
            
            $duration_text = formatDuration($final_duration['duration_minutes'] ?? 0);

        } else {
            // Fallback jika is_on_duty=TRUE tapi log aktif tidak ditemukan
            $duration_text = '0j 0m (Log Error)';
        }
        
        // 3. Update status employee
        $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
        $stmt_update_employee->bind_param("i", $employee_id);
        $stmt_update_employee->execute();
        $stmt_update_employee->close();
        
        $conn->commit();
        
        // Kirim notifikasi Discord
        sendDiscordNotification(['employee_name' => $employee_name, 'event_type' => 'clock_out', 'duration' => $duration_text], 'clock_event');

        send_json_response('success', "Off Duty berhasil! Total waktu tugas: {$duration_text}.", ['state' => 'clocked_out', 'duration' => $duration_text]);
    }

} catch (Exception $e) {
    $conn->rollback();
    error_log("API Error: " . $e->getMessage());
    send_json_response('error', "Internal server error during transaction: " . $e->getMessage(), ['code' => 500, 'db_error' => $conn->error]);
}

?>