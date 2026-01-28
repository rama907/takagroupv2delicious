<?php
// File: api/get_pending_requests.php
// API Endpoint untuk mengambil semua permohonan pending.

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

// --- 2. Autentikasi API Key ---
if ($secret_key !== API_SECRET_KEY) {
    send_json_response('error', 'Unauthorized: Invalid API Key.', ['code' => 401]);
}

try {
    $pending_requests = [];
    
    // Query UNION ALL untuk mengambil semua pending requests dari 5 tabel berbeda
    $query = "
        (
            SELECT lr.id, lr.employee_id, e.name as employee_name, 'leave' as type, 'Izin' as display_type, lr.created_at, CONCAT(DATE(lr.start_date), ' hingga ', DATE(lr.end_date)) as details
            FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
            WHERE lr.status = 'pending'
        )
        UNION ALL
        (
            SELECT rr.id, rr.employee_id, e.name as employee_name, 'resign' as type, 'Resign' as display_type, rr.created_at, CONCAT('Resign: ', DATE(rr.start_date)) as details
            FROM resignation_requests rr JOIN employees e ON rr.employee_id = e.id
            WHERE rr.status = 'pending'
        )
        UNION ALL
        (
            SELECT mdr.id, mdr.employee_id, e.name as employee_name, 'manual' as type, 'Input Manual' as display_type, mdr.created_at, CONCAT(DATE(mdr.duty_date), ' ', mdr.start_time, ' - ', mdr.end_time) as details
            FROM manual_duty_requests mdr JOIN employees e ON mdr.employee_id = e.id
            WHERE mdr.status = 'pending'
        )
        UNION ALL
        (
            SELECT aer.id, NULL as employee_id, aer.employee_name as employee_name, 'new_employee' as type, 'Anggota Baru' as display_type, aer.created_at, CONCAT('Jabatan: ', aer.requested_role) as details
            FROM add_employee_requests aer
            WHERE aer.status = 'pending'
        )
        UNION ALL
        (
            SELECT pr.id, pr.employee_id, e.name as employee_name, 'password_reset' as type, 'Reset Password' as display_type, pr.created_at, CONCAT('Tipe: ', pr.reset_type) as details
            FROM password_reset_requests pr JOIN employees e ON pr.employee_id = e.id
            WHERE pr.status = 'pending'
        )
        ORDER BY created_at ASC
    ";
    
    $result = $conn->query($query);
    if ($result === false) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $pending_requests = $result->fetch_all(MYSQLI_ASSOC);
    
    send_json_response('success', 'Pending requests retrieved successfully.', $pending_requests);

} catch (Exception $e) {
    error_log("API Pending Request Error: " . $e->getMessage());
    send_json_response('error', "Internal server error: " . $e->getMessage());
}
?>