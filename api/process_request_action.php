<?php
// File: api/process_request_action.php
// API Endpoint untuk menyetujui/menolak permohonan dari Discord Buttons.

header('Content-Type: application/json');
require_once '../config.php'; 

// --- Utility Functions ---
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
$request_id = (int)($request_data['request_id'] ?? 0);
$request_type = $request_data['request_type'] ?? '';
$action = strtolower($request_data['action'] ?? '');
$admin_id = (int)($request_data['admin_id'] ?? 0); // Discord ID admin

// --- 2. Autentikasi API Key ---
if ($secret_key !== API_SECRET_KEY) {
    send_json_response('error', 'Unauthorized: Invalid API Key.', ['code' => 401]);
}

// Pastikan admin_id ada (Discord ID)
if ($request_id <= 0 || empty($request_type) || ($action !== 'approve' && $action !== 'reject') || $admin_id <= 0) {
    send_json_response('error', 'Invalid or incomplete request parameters.', ['code' => 400]);
}

// Map Request Type ke konfigurasi database
$table_map = [
    'leave' => ['table' => 'leave_requests', 'notif_type' => 'Cuti'],
    'resign' => ['table' => 'resignation_requests', 'notif_type' => 'Resign'],
    'manual' => ['table' => 'manual_duty_requests', 'notif_type' => 'Input Jam Manual'],
    'new_employee' => ['table' => 'add_employee_requests', 'notif_type' => 'Anggota Baru'],
    'password_reset' => ['table' => 'password_reset_requests', 'notif_type' => 'Reset Password'],
];

if (!isset($table_map[$request_type])) {
    send_json_response('error', 'Invalid request type.', ['code' => 400]);
}

$request_config = $table_map[$request_type];
$table = $request_config['table'];
$action_type = ($action === 'approve') ? 'approved' : 'rejected';

// Ambil ID Karyawan Admin dari Discord ID
$admin_data = getEmployeeByDiscordId($admin_id);
if (!$admin_data) {
     send_json_response('error', 'Admin user not found in employee list (Discord ID missing).', ['code' => 403]);
}
$admin_employee_id = $admin_data['id'];
$admin_name = $admin_data['name'];


$conn->begin_transaction();

try {
    // A. Ambil data permohonan yang pending
    $check_stmt_sql = "SELECT * FROM {$table} WHERE id = ? AND status = 'pending'";
    $check_stmt = $conn->prepare($check_stmt_sql);
    if (!$check_stmt) { throw new Exception("Failed to prepare check query: " . $conn->error); }
    $check_stmt->bind_param("i", $request_id);
    $check_stmt->execute();
    $request_data_db = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if (!$request_data_db) {
        throw new Exception("Request not found or already processed.");
    }

    $employee_id_affected = $request_data_db['employee_id'] ?? null;
    $employee_name_affected = getEmployeeNameById($employee_id_affected);


    // B. Logika Khusus untuk Approval
    if ($action === 'approve') {
        if ($table === 'manual_duty_requests') {
            // Replicate manual duty approval logic from requests.php
            $start_datetime_str = $request_data_db['duty_date'] . ' ' . $request_data_db['start_time'];
            $end_datetime_str = $request_data_db['duty_date'] . ' ' . $request_data_db['end_time'];
            
            $start_timestamp = strtotime($start_datetime_str);
            $end_timestamp = strtotime($end_datetime_str);
            
            if ($end_timestamp <= $start_timestamp) {
                $end_timestamp += 24 * 60 * 60; 
                $end_datetime_str = date('Y-m-d H:i:s', $end_timestamp);
            }
            
            $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
            
            $insert_duty_stmt = $conn->prepare("
                INSERT INTO duty_logs (employee_id, duty_start, duty_end, duration_minutes, is_manual, approved_by, status)
                VALUES (?, ?, ?, ?, 1, ?, 'completed')
            ");
            if (!$insert_duty_stmt) { throw new Exception("Failed to prepare insert duty logs query: " . $conn->error); }
            $insert_duty_stmt->bind_param("issii", $employee_id_affected, $start_datetime_str, $end_datetime_str, $duration_minutes, $admin_employee_id);
            if (!$insert_duty_stmt->execute()) { throw new Exception("Failed to insert into duty logs: " . $insert_duty_stmt->error); }
            $insert_duty_stmt->close();
            
        } elseif ($table === 'add_employee_requests') {
            // Replicate add employee approval logic from requests.php
            $insert_employee_stmt = $conn->prepare("
                INSERT INTO employees (name, role, password)
                VALUES (?, ?, ?)
            ");
            if (!$insert_employee_stmt) { throw new Exception("Failed to prepare insert employee query: " . $conn->error); }
            $insert_employee_stmt->bind_param("sss", $request_data_db['employee_name'], $request_data_db['requested_role'], $request_data_db['requested_password']);
            if (!$insert_employee_stmt->execute()) { throw new Exception("Failed to add new employee: " . $insert_employee_stmt->error); }
            $insert_employee_stmt->close();
            $employee_name_affected = $request_data_db['employee_name']; // Update name for notification
            
        } elseif ($table === 'password_reset_requests') {
            // Replicate password reset approval logic from requests.php
            $update_password_stmt = $conn->prepare("
                UPDATE employees SET password = ? WHERE id = ?
            ");
            if (!$update_password_stmt) { throw new Exception("Failed to prepare update password query: " . $conn->error); }
            $update_password_stmt->bind_param("si", $request_data_db['requested_password'], $employee_id_affected);
            if (!$update_password_stmt->execute()) { throw new Exception("Failed to update employee password: " . $update_password_stmt->error); }
            $update_password_stmt->close();
        }
    }

    // C. Update status permohonan
    $update_stmt_sql = "UPDATE {$table} SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'";
    $update_stmt = $conn->prepare($update_stmt_sql);
    if (!$update_stmt) { throw new Exception("Failed to prepare update status query: " . $conn->error); }
    $update_stmt->bind_param("sii", $action_type, $admin_employee_id, $request_id);
    
    if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
        $conn->commit();
        $final_message = $request_config['notif_type'] . " for " . $employee_name_affected . " was " . $action_type . " by " . $admin_name . ".";
        
        // Kirim Notifikasi Discord (Re-using request_status_update)
        sendDiscordNotification([
            'employee_name' => $employee_name_affected,
            'request_type' => $request_config['notif_type'],
            'status' => $action_type,
            'approved_by_name' => $admin_name,
        ], 'request_status_update');

        send_json_response('success', $final_message);
        
    } else {
        throw new Exception("Failed to update request status or already processed.");
    }
    $update_stmt->close();

} catch (Exception $e) {
    $conn->rollback();
    error_log("API Action Error: " . $e->getMessage());
    send_json_response('error', "Transaction failed: " . $e->getMessage());
}
?>