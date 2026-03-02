<?php
// File: config.php (Updated for Sweet Coffee & Matcha Misu)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

// --- KONSTANTA BARU UNTUK DISCORD BOT API ---
// GANTI 'MASUKKAN_KUNCI_RAHASIA_ANDA_DISINI' dengan kunci unik (contoh: aBc123XyZ789)
define('API_SECRET_KEY', '564d5c059a374f249a79e481bbf9df15'); 
// -------------------------------------------

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'imjutwnp_delicious');
define('DB_PASS', 'eRJrpFtGzSAtBupHSfAb');
define('DB_NAME', 'imjutwnp_delicious');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8");

// Start session
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to check user role
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['role'], $roles);
}

// Function to get current user
function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;
    
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// NEW FUNCTION: Get Employee by Discord ID (Digunakan oleh API Bot)
function getEmployeeByDiscordId($discordId) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM employees WHERE discord_id = ? AND status = 'active'");
    if (!$stmt) return false;
    $stmt->bind_param("s", $discordId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

// Function to format duration
function formatDuration($minutes) {
    if ($minutes < 0) return "0j 0m"; // Tangani durasi negatif
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return "{$hours}j {$mins}m";
}

// Function to get role display name
function getRoleDisplayName($role) {
    $roles = [
        'ceo' => 'CEO',
        'direktur' => 'Direktur',
        'wakil_direktur' => 'Wakil Direktur',
        'manager' => 'Manager',
        'chef' => 'Chef',
        'waiters' => 'Waiters',
        'karyawan' => 'Karyawan',
        'magang' => 'Magang'
    ];
    return $roles[$role] ?? ucfirst($role);
}

// Fungsi untuk mendapatkan nama karyawan berdasarkan ID
function getEmployeeNameById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
    if (!$stmt) {
        error_log("Error preparing getEmployeeNameById statement: " . $conn->error);
        return 'Unknown Employee';
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['name'] ?? 'Tidak Dikenal';
}

// Mendapatkan hitungan pending request yang terpisah
function getPendingRequestCounts() {
    global $conn;
    $counts = [
        'employee_requests' => 0, 
        'booking_requests' => 0,  
        'total' => 0
    ];

    $query = "
        SELECT 
            (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM resignation_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM manual_duty_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM add_employee_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending') as count
    ";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $counts['employee_requests'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }

    $query = "SELECT COUNT(*) as count FROM room_bookings WHERE booking_status = 'pending_approval'";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $counts['booking_requests'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }
    
    $counts['total'] = $counts['employee_requests'] + $counts['booking_requests'];
    
    return $counts;
}

// FUNGSI LAMA: Dipertahankan untuk kompatibilitas, kini mengembalikan total.
function getPendingRequestCount() {
    $counts = getPendingRequestCounts();
    return $counts['total'];
}

// Fungsi untuk memeriksa apakah seorang karyawan adalah Talent
function isTalent($employeeId) {
    global $conn;
    if (!$employeeId) return false;
    
    $stmt = $conn->prepare("SELECT is_talent FROM talent_assignments WHERE employee_id = ? AND is_talent = TRUE");
    if (!$stmt) return false;
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$result;
}

// Fungsi untuk mendapatkan daftar semua Talent aktif
function getAllActiveTalents() {
    global $conn;
    $query = "
        SELECT e.id, e.name
        FROM employees e
        JOIN talent_assignments ta ON e.id = ta.employee_id
        WHERE e.status = 'active' AND ta.is_talent = TRUE
        ORDER BY e.name ASC
    ";
    $result = $conn->query($query);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Fungsi untuk mendapatkan total share talent yang terakumulasi
function getTotalTalentShare($talentName, $statusFilter = 'Pending') {
    global $conn;
    $stmt = $conn->prepare("SELECT COALESCE(SUM(talent_share), 0) as total_share FROM sales_table_room WHERE talent_name = ? AND talent_share_status = ?");
    if (!$stmt) return 0;
    $stmt->bind_param("ss", $talentName, $statusFilter);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($result['total_share'] ?? 0);
}

// Fungsi untuk mengirim notifikasi ke Discord
function sendDiscordNotification($data, $type = 'info') {
    // --- 1. Konfigurasi Webhook & Bot Khusus ---
    
    // Webhook 1: Pusat Notifikasi (Umum, Clock Event, Admin System Action)
    $general_webhook_url = 'https://discord.com/api/webhooks/1447150151616692248/rISdem1QO20s8lGx-tQRWwZJKtm6xNbi6J7CPRghFu_-H5RXcS9GqJifc6hoZOAzZ8g7';
    $general_bot_name = "Delicious Bot";

    // Webhook 2: Permohonan & Surat Menyurat (Leave, Resign, Manual Duty, Booking, dll)
    $request_webhook_url = 'https://discord.com/api/webhooks/1447150278423089183/DDtsqBYnK86_LUfSlKDoiDxMgmw-9dsPOic4dLUOxavLGmpu9_Zdy50BsqDm2HXnmpDX'; 
    $request_bot_name = "Delicious Request Bot";

    // Webhook 3: Stok Kulkas
    $refrigerator_webhook_url = 'https://discord.com/api/webhooks/1447150486560964669/nKYedvCM5x__MqZcBd_YKzV8vx2ggBsTKJMX7HbQ4Y898O-cxksR7fnPMm5t1xUwI1MI';
    $refrigerator_bot_name = "Delicious Kulkas Bot";

    // Webhook 4: Stok Gudang
    $warehouse_webhook_url = 'https://discord.com/api/webhooks/1447150595298431106/IYbdSNQL_dCTDtcpfrp4pJIuGdQbQkrUfmw4Kw82Qo4-yfpVUeuBKvYsOKZ1FV-pMFvR';
    $warehouse_bot_name = "Delicious Gudang Bot";
    
    // Webhook 5: Penjualan
    $sales_webhook_url = 'https://discord.com/api/webhooks/1447150379480514601/2AY9H6dSIR7rPei-5BLakZAftul6MuH92RCsef4YPHAMuKkTwKQWJFq0QZIp-yr4WsZS'; 
    $sales_bot_name = "Delicious Sales Bot";
    
    // Webhook 6: Laporan Rekap Absensi
    $report_webhook_url = 'https://discord.com/api/webhooks/1447150670494044233/6qsgCEmYegrdMpj2iQCgCJIB7YKlydL8BNb56WFxsfK2mCBsbggjHJ5i23mDRfk2idyp'; 
    $report_bot_name = "Delicious Report Bot";

    // Webhook 7: Rekap Jam Duty
    $duty_recap_webhook_url = 'https://discord.com/api/webhooks/1447150742308786186/UPFPEaFTgpn9asP9CD7SxIxO5nJSYVWtQMmen6ZnBFxf7pvSO6SXlWVq4NchQtU2Cbu8'; 
    $duty_recap_bot_name = "Delicious Duty Recap Bot";

    // Webhook 8: Gaji/Payroll
    $salary_webhook_url = 'https://discord.com/api/webhooks/1447515156199247913/EcPwijRGrqGYijlaXP4Js_CfCdHttklGsPsEAVAHb8PisoPdxBx2mpLkjN30O286PJXO'; 
    $salary_bot_name = "Delicious Payroll Bot"; 
    // =====================================

    // --- 2. Tentukan Webhook & Bot yang Digunakan ---
    $webhooks_to_send = [];
    $username = $general_bot_name; 
    $avatar_url = "https://i.imghippo.com/files/lKv3675HWk.png"; 

    $request_types = [
        'leave_request_submitted', 'resignation_request_submitted', 'manual_duty_request_submitted', 
        'request_status_update', 'warning_letter_issued', 'warning_letter_deleted', 
        'new_employee_request_submitted', 'password_reset_request_submitted',
        'room_booking_submitted', 'booking_status_updated', 'payment_status_updated',
        'duty_log_deleted'
    ];
    $sales_types = ['sale_input', 'sale_deleted', 'cooking_input', 'cooking_deleted']; 
    $recap_types = ['daily_absent_recap', 'daily_duty_recap']; 

    $refrigerator_types = ['refrigerator_deposit', 'refrigerator_withdraw'];
    $warehouse_types = ['warehouse_deposit', 'warehouse_withdraw'];
    $salary_types = ['salary_paid_single', 'salary_unpaid_single', 'salary_unpaid_all']; 
    $general_types = ['clock_event', 'admin_employee_action', 'admin_system_action'];

    // Routing Logic
    if (in_array($type, $refrigerator_types)) {
        if (strpos($refrigerator_webhook_url, 'WEBHOOK_URL_HERE') === false) { $webhooks_to_send[] = $refrigerator_webhook_url; }
        $username = $refrigerator_bot_name;
    } elseif (in_array($type, $warehouse_types)) {
        if (strpos($warehouse_webhook_url, 'WEBHOOK_URL_HERE') === false) { $webhooks_to_send[] = $warehouse_webhook_url; }
        $username = $warehouse_bot_name;
    } elseif (in_array($type, $sales_types)) {
        if (strpos($sales_webhook_url, 'placeholder_sales_webhook') === false) { $webhooks_to_send[] = $sales_webhook_url; }
        $username = $sales_bot_name;
    } elseif (in_array($type, $salary_types)) { 
        if (strpos($salary_webhook_url, 'SALARY_WEBHOOK_ID') === false) { $webhooks_to_send[] = $salary_webhook_url; }
        $username = $salary_bot_name;
    } elseif (in_array($type, $recap_types)) {
         if ($type === 'daily_duty_recap') {
            if (strpos($duty_recap_webhook_url, 'placeholder_duty_recap') === false) { $webhooks_to_send[] = $duty_recap_webhook_url; }
            $username = $duty_recap_bot_name;
        } else {
            if (strpos($report_webhook_url, 'placeholder_report_webhook') === false) { $webhooks_to_send[] = $report_webhook_url; }
            $username = $report_bot_name;
        }
    } elseif (in_array($type, $request_types)) {
        if (strpos($request_webhook_url, 'WEBHOOK_URL_HERE') === false) { $webhooks_to_send[] = $request_webhook_url; }
        $username = $request_bot_name;
    } else {
        $webhooks_to_send[] = $general_webhook_url;
    }

    // --- Critical Fallback Check ---
    if (empty($webhooks_to_send) || (count($webhooks_to_send) === 1 && strpos($webhooks_to_send[0], 'WEBHOOK_URL_HERE') !== false) || (count($webhooks_to_send) === 1 && strpos($webhooks_to_send[0], 'placeholder') !== false) || (count($webhooks_to_send) === 1 && strpos($webhooks_to_send[0], 'SALARY_WEBHOOK_ID') !== false)) {
         $webhooks_to_send = [$general_webhook_url];
         $username = $general_bot_name;
    }

    // Definisikan warna untuk setiap tipe notifikasi
    $colors = [
        'info' => 3447003,    
        'success' => 3066993, 
        'warning' => 16776960,
        'danger' => 15158332, 
        'employee_action' => 5793266, 
        'sale_input' => 3066993, 
        'sale_deleted' => 15548997, 
        'cooking_input' => 10181046, // Purple
        'cooking_deleted' => 10038562, // Dark Purple
        'salary_paid_single' => 3066993, 
        'salary_unpaid_single' => 16776960, 
        'salary_unpaid_all' => 16750899,
        'warning_letter_issued' => 16750899, 
        'warning_letter_deleted' => 15158332, 
        'new_employee_request_submitted' => 3447003,
        'password_reset_request_submitted' => 16776960,
        'refrigerator_deposit' => 3066993, 
        'refrigerator_withdraw' => 15158332,
        'warehouse_deposit' => 3066993,
        'warehouse_withdraw' => 15158332,
        'daily_absent_recap' => 15158332, 
        'daily_duty_recap' => 3447003,
    ];
    $color = $colors[$type] ?? 0; 

    // Inisialisasi embed dasar
    $embed = [
        'title' => '',
        'description' => '',
        'color' => $color,
        'timestamp' => date('c'), 
        'footer' => [
            'text' => 'Royale Grill & Bar Management System', 
        ],
    ];
    
    // FIX PENTING: Perbaikan fungsi rekursif untuk HTML decoding
    $decode = function($str) use (&$decode) {
        if (is_array($str)) {
            // Menggunakan array_map rekursif (dipanggil melalui $decode)
            $new_array = [];
            foreach ($str as $key => $value) {
                $new_array[$key] = $decode($value);
            }
            return $new_array;
        }
        // Hanya decode jika itu string
        return is_string($str) ? htmlspecialchars_decode($str, ENT_QUOTES) : $str;
    };
    
    // Mengaplikasikan decoding ke seluruh data
    $decoded_data = $decode($data);

    // Logika untuk mengisi embed berdasarkan 'type' dan 'data'
    switch ($type) {
        case 'clock_event': 
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            if (($decoded_data['event_type'] ?? '') === 'clock_in') {
                $embed['title'] = "⏰ Karyawan Mulai Bertugas!";
                $embed['description'] = "**{$employee_name}** telah mulai bertugas.";
                $embed['color'] = $colors['info'];
                $embed['fields'] = [
                    ['name' => 'Waktu Mulai', 'value' => date('H:i:s'), 'inline' => true],
                    ['name' => 'Status', 'value' => '🟢 On Duty', 'inline' => true],
                ];
            } elseif (($decoded_data['event_type'] ?? '') === 'clock_out') {
                $embed['title'] = "⏸️ Karyawan Selesai Bertugas!";
                $embed['description'] = "Karyawan **{$employee_name}** telah selesai bertugas.";
                $embed['color'] = $colors['info'];
                $embed['fields'] = [
                    ['name' => 'Waktu Selesai', 'value' => date('H:i:s'), 'inline' => true],
                    ['name' => 'Durasi Tugas', 'value' => $decoded_data['duration'] ?? 'N/A', 'inline' => true],
                    ['name' => 'Status', 'value' => '🔴 Off Duty', 'inline' => true],
                ];
            }
            break;

        case 'leave_request_submitted': 
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $embed['title'] = "📝 Permohonan Cuti Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan cuti.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Periode Cuti', 'value' => date('d/m/Y', strtotime($decoded_data['start_date'] ?? '')) . ' - ' . date('d/m/Y', strtotime($decoded_data['end_date'] ?? '')), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Alasan (OOC)', 'value' => empty($decoded_data['reason_ooc']) ? '-' : $decoded_data['reason_ooc']],
                ['name' => 'Alasan (IC)', 'value' => empty($decoded_data['reason_ic']) ? '-' : $decoded_data['reason_ic']],
            ];
            break;
        
        case 'resignation_request_submitted':
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $embed['title'] = "📄 Permohonan Resign Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan resign.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Tanggal Resign', 'value' => date('d/m/Y', strtotime($decoded_data['resignation_date'] ?? '')), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Passport', 'value' => $decoded_data['passport'] ?? 'N/A', 'inline' => true],
                ['name' => 'CID', 'value' => $decoded_data['cid'] ?? 'N/A', 'inline' => true],
                ['name' => 'Alasan (OOC)', 'value' => empty($decoded_data['reason_ooc']) ? '-' : $decoded_data['reason_ooc']],
                ['name' => 'Alasan (IC)', 'value' => empty($decoded_data['reason_ic']) ? '-' : $decoded_data['reason_ic']],
            ];
            break;

        case 'manual_duty_request_submitted':
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $embed['title'] = "⏱️ Permohonan Input Jam Manual Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan input jam manual.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($decoded_data['duty_date'] ?? '')), 'inline' => true],
                ['name' => 'Periode Waktu', 'value' => date('H:i', strtotime($decoded_data['start_time'] ?? '')) . ' - ' . date('H:i', strtotime($decoded_data['end_time'] ?? '')), 'inline' => true],
                ['name' => 'Durasi', 'value' => $decoded_data['duration_text'] ?? 'N/A', 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Alasan', 'value' => empty($decoded_data['reason']) ? '-' : $decoded_data['reason']],
            ];
            break;

        case 'request_status_update':
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $approver_name = $decoded_data['approved_by_name'] ?? 'N/A';
            $status_text = '';
            $icon = '';
            $color_status = $colors['info'];

            if (($decoded_data['status'] ?? '') === 'approved') {
                $status_text = 'Disetujui';
                $icon = '✅';
                $color_status = $colors['success'];
            } elseif (($decoded_data['status'] ?? '') === 'rejected') {
                $status_text = 'Ditolak';
                $icon = '❌';
                $color_status = $colors['danger'];
            }
            
            $embed['title'] = "{$icon} Permohonan " . htmlspecialchars($decoded_data['request_type'] ?? 'N/A') . " Diperbarui!";
            $embed['description'] = "Permohonan **" . htmlspecialchars($decoded_data['request_type'] ?? 'N/A') . "** dari **{$employee_name}** telah **{$status_text}** oleh **{$approver_name}**.";
            $embed['color'] = $color_status;
            $embed['fields'] = [
                ['name' => 'Karyawan', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Status', 'value' => "{$icon} {$status_text}", 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $approver_name, 'inline' => true],
            ];
            break;

        case 'admin_employee_action':
            $admin_name = $decode($decoded_data['admin_name'] ?? 'N/A');
            $target_name = $decoded_data['target_employee_name'] ?? 'N/A';
            $embed['color'] = $colors['employee_action'];
            
            if (($decoded_data['action_type'] ?? '') === 'update_role') {
                $old_role_display = getRoleDisplayName($decoded_data['old_value'] ?? 'N/A');
                $new_role_display = getRoleDisplayName($decoded_data['new_value'] ?? 'N/A');
                $embed['title'] = "👥 Perubahan Jabatan Anggota!";
                $embed['description'] = "Jabatan **{$target_name}** telah diubah oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Jabatan Lama', 'value' => $old_role_display, 'inline' => true],
                    ['name' => 'Jabatan Baru', 'value' => $new_role_display, 'inline' => true],
                ];
            } elseif (($decoded_data['action_type'] ?? '') === 'deactivate_employee') {
                $embed['title'] = "⛔ Anggota Dinonaktifkan!";
                $embed['description'] = "Anggota **{$target_name}** telah dinonaktifkan oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Status', 'value' => '🔴 Tidak Aktif', 'inline' => true],
                ];
            } elseif (($decoded_data['action_type'] ?? '') === 'add_employee') {
                $role_display = getRoleDisplayName($decoded_data['role'] ?? 'N/A');
                $embed['title'] = "➕ Anggota Baru Ditambahkan!";
                $embed['description'] = "Anggota baru **{$target_name}** ({$role_display}) telah ditambahkan oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Nama Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Jabatan', 'value' => $role_display, 'inline' => true],
                    ['name' => 'Ditambahkan Oleh', 'value' => $admin_name, 'inline' => true],
                ];
            }
            break;

        case 'admin_system_action':
            $admin_name = $decode($decoded_data['admin_name'] ?? 'N/A');
            $embed['color'] = $colors['employee_action'];
            
            if (($decoded_data['action_type'] ?? '') === 'reset_weekly_data') {
                $embed['title'] = "🔄 Data Mingguan Direset!";
                $embed['description'] = "Data jam tugas dan penjualan mingguan telah direset oleh **{$admin_name}**.";
            } elseif (($decoded_data['action_type'] ?? '') === 'mass_activity_delete') {
                $embed['title'] = "⚠️ Reset Total Data Aktivitas!";
                $embed['description'] = "Tindakan pembersihan data massal oleh **{$admin_name}**.";
                $embed['color'] = $colors['danger'];
                $embed['fields'] = [
                    ['name' => 'Sales Dihapus', 'value' => $decoded_data['deleted_sales'] ?? 0, 'inline' => true],
                    ['name' => 'Cooking Dihapus', 'value' => $decoded_data['deleted_cooking'] ?? 0, 'inline' => true],
                    ['name' => 'Duty Logs Dihapus', 'value' => $decoded_data['deleted_duty_logs'] ?? 0, 'inline' => true],
                ];
            }
            break;

        case 'sale_input':
            $employee_name = $decode($decoded_data['employee_name'] ?? 'N/A');
            
            // Kolom baru sesuai struktur database terbaru
            $western = $decoded_data['paket_western'] ?? 0; 
            $nusantara = $decoded_data['paket_nusantara'] ?? 0;
            $kids_meal = $decoded_data['paket_kids_meal'] ?? 0;
            $happy_bites = $decoded_data['happy_bites'] ?? 0;
            $royale = $decoded_data['paket_royale'] ?? 0;
            $sweet_coffee = $decoded_data['paket_sweet_coffee'] ?? 0;
            $matchamisu = $decoded_data['paket_matchamisu'] ?? 0;

            // Menghitung total item yang terjual
            $total_items_sold = $western + $nusantara + $kids_meal + $happy_bites + $royale + $sweet_coffee + $matchamisu; 

            $embed['title'] = "💰 Data Penjualan Baru Diinput! (Total: {$total_items_sold})";
            $embed['description'] = "**{$employee_name}** telah menginput data penjualan paket Resto. Detail di bawah:";
            $embed['color'] = $colors['success'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($decoded_data['date'] ?? '')), 'inline' => true],
                ['name' => 'Waktu Input', 'value' => date('H:i:s', strtotime($decoded_data['input_time'] ?? '')), 'inline' => true],
            ];

            // Menampilkan paket-paket yang terjual
            if ($western > 0) $embed['fields'][] = ['name' => 'Paket Western', 'value' => $western, 'inline' => true];
            if ($nusantara > 0) $embed['fields'][] = ['name' => 'Paket Nusantara', 'value' => $nusantara, 'inline' => true];
            if ($kids_meal > 0) $embed['fields'][] = ['name' => 'Paket Kids Meal', 'value' => $kids_meal, 'inline' => true];
            if ($happy_bites > 0) $embed['fields'][] = ['name' => 'Happy Bites', 'value' => $happy_bites, 'inline' => true];
            if ($royale > 0) $embed['fields'][] = ['name' => 'Paket Royale', 'value' => $royale, 'inline' => true];
            if ($sweet_coffee > 0) $embed['fields'][] = ['name' => 'Sweet Coffee', 'value' => $sweet_coffee, 'inline' => true];
            if ($matchamisu > 0) $embed['fields'][] = ['name' => 'Matcha Misu', 'value' => $matchamisu, 'inline' => true];
            
            $embed['fields'][] = ['name' => '', 'value' => '', 'inline' => false];
            break;
        
        case 'sale_deleted':
            $employee_name = $decode($decoded_data['employee_name'] ?? 'N/A');
            $sales_date_time = $decode($decoded_data['sales_date_time'] ?? 'N/A');
            
            // Gunakan nama kolom baru, dengan fallback ke kolom lama jika perlu
            $western = $decoded_data['paket_western'] ?? ($decoded_data['paket_sake'] ?? 0); 
            $nusantara = $decoded_data['paket_nusantara'] ?? ($decoded_data['paket_anggur_merah'] ?? 0);
            $kids_meal = $decoded_data['paket_kids_meal'] ?? ($decoded_data['paket_tuak'] ?? 0);
            $happy_bites = $decoded_data['happy_bites'] ?? 0;
            $royale = $decoded_data['paket_royale'] ?? ($decoded_data['paket_vip_person'] ?? 0);
            $sweet_coffee = $decoded_data['paket_sweet_coffee'] ?? 0;
            $matchamisu = $decoded_data['paket_matchamisu'] ?? 0;
            
            $total_items_deleted = $western + $nusantara + $kids_meal + $happy_bites + $royale + $sweet_coffee + $matchamisu;

            $embed['title'] = "🗑️ Data Penjualan Dihapus!";
            $embed['description'] = "Data penjualan dari **{$employee_name}** pada **{$sales_date_time}** telah dihapus.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Waktu Input Asli', 'value' => $sales_date_time, 'inline' => true],
            ];
            
            if ($western > 0) $embed['fields'][] = ['name' => 'Western (Dihapus)', 'value' => $western, 'inline' => true];
            if ($nusantara > 0) $embed['fields'][] = ['name' => 'Nusantara (Dihapus)', 'value' => $nusantara, 'inline' => true];
            if ($kids_meal > 0) $embed['fields'][] = ['name' => 'Kids Meal (Dihapus)', 'value' => $kids_meal, 'inline' => true];
            if ($happy_bites > 0) $embed['fields'][] = ['name' => 'Happy Bites (Dihapus)', 'value' => $happy_bites, 'inline' => true];
            if ($royale > 0) $embed['fields'][] = ['name' => 'Royale (Dihapus)', 'value' => $royale, 'inline' => true];
            if ($sweet_coffee > 0) $embed['fields'][] = ['name' => 'Sweet Coffee (Dihapus)', 'value' => $sweet_coffee, 'inline' => true];
            if ($matchamisu > 0) $embed['fields'][] = ['name' => 'Matcha Misu (Dihapus)', 'value' => $matchamisu, 'inline' => true];

            $embed['fields'][] = ['name' => 'Total Item Dihapus', 'value' => $total_items_deleted, 'inline' => false];
            break;

        case 'cooking_input':
            $employee_name = $decode($decoded_data['employee_name'] ?? 'N/A');
            
            $western = $decoded_data['paket_western'] ?? 0; 
            $nusantara = $decoded_data['paket_nusantara'] ?? 0;
            $kids_meal = $decoded_data['paket_kids'] ?? 0;
            $happy_bites = $decoded_data['happy_bites'] ?? 0;
            $royale = $decoded_data['paket_royale'] ?? 0;
            $sweet_coffee = $decoded_data['paket_sweet_coffee'] ?? 0;
            $matchamisu = $decoded_data['paket_matchamisu'] ?? 0;

            $total_items = $western + $nusantara + $kids_meal + $happy_bites + $royale + $sweet_coffee + $matchamisu; 

            $embed['title'] = "🔪 Data Masak Diinput! (Total: {$total_items})";
            $embed['description'] = "**{$employee_name}** telah menginput data masak (pengurangan stok gudang).";
            $embed['color'] = $colors['cooking_input'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($decoded_data['date'] ?? '')), 'inline' => true],
                ['name' => 'Waktu Input', 'value' => date('H:i:s', strtotime($decoded_data['input_time'] ?? '')), 'inline' => true],
            ];

            if ($western > 0) $embed['fields'][] = ['name' => 'Western', 'value' => $western, 'inline' => true];
            if ($nusantara > 0) $embed['fields'][] = ['name' => 'Nusantara', 'value' => $nusantara, 'inline' => true];
            if ($kids_meal > 0) $embed['fields'][] = ['name' => 'Kids Meal', 'value' => $kids_meal, 'inline' => true];
            if ($happy_bites > 0) $embed['fields'][] = ['name' => 'Happy Bites', 'value' => $happy_bites, 'inline' => true];
            if ($royale > 0) $embed['fields'][] = ['name' => 'Royale', 'value' => $royale, 'inline' => true];
            if ($sweet_coffee > 0) $embed['fields'][] = ['name' => 'Sweet Coffee', 'value' => $sweet_coffee, 'inline' => true];
            if ($matchamisu > 0) $embed['fields'][] = ['name' => 'Matcha Misu', 'value' => $matchamisu, 'inline' => true];
            
            $embed['fields'][] = ['name' => '', 'value' => '', 'inline' => false];
            break;

        case 'cooking_deleted':
            $employee_name = $decode($decoded_data['employee_name'] ?? 'N/A');
            $date = date('d/m/Y', strtotime($decoded_data['date'] ?? ''));
            
            $western = $decoded_data['paket_western'] ?? 0; 
            $nusantara = $decoded_data['paket_nusantara'] ?? 0;
            $kids_meal = $decoded_data['paket_kids'] ?? 0;
            $happy_bites = $decoded_data['happy_bites'] ?? 0;
            $royale = $decoded_data['paket_royale'] ?? 0;
            $sweet_coffee = $decoded_data['paket_sweet_coffee'] ?? 0;
            $matchamisu = $decoded_data['paket_matchamisu'] ?? 0;
            
            $total_items = $western + $nusantara + $kids_meal + $happy_bites + $royale + $sweet_coffee + $matchamisu;

            $embed['title'] = "🗑️ Log Masak Dihapus!";
            $embed['description'] = "Log masak dari **{$employee_name}** pada tanggal **{$date}** telah dihapus.";
            $embed['color'] = $colors['cooking_deleted'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Tanggal Log', 'value' => $date, 'inline' => true],
            ];
            
            if ($western > 0) $embed['fields'][] = ['name' => 'Western', 'value' => $western, 'inline' => true];
            if ($nusantara > 0) $embed['fields'][] = ['name' => 'Nusantara', 'value' => $nusantara, 'inline' => true];
            if ($kids_meal > 0) $embed['fields'][] = ['name' => 'Kids Meal', 'value' => $kids_meal, 'inline' => true];
            if ($happy_bites > 0) $embed['fields'][] = ['name' => 'Happy Bites', 'value' => $happy_bites, 'inline' => true];
            if ($royale > 0) $embed['fields'][] = ['name' => 'Royale', 'value' => $royale, 'inline' => true];
            if ($sweet_coffee > 0) $embed['fields'][] = ['name' => 'Sweet Coffee', 'value' => $sweet_coffee, 'inline' => true];
            if ($matchamisu > 0) $embed['fields'][] = ['name' => 'Matcha Misu', 'value' => $matchamisu, 'inline' => true];

            $embed['fields'][] = ['name' => 'Total Item Dihapus', 'value' => $total_items, 'inline' => false];
            break;
            
        case 'refrigerator_deposit':
        case 'refrigerator_withdraw':
        case 'warehouse_deposit':
        case 'warehouse_withdraw':
            $is_deposit = (strpos($type, '_deposit') !== false);
            $is_refrigerator = (strpos($type, 'refrigerator') !== false);
            $stock_type_name = $is_refrigerator ? 'Resto (Kulkas)' : 'Gudang'; 
            $action_text = $is_deposit ? 'DEPOSIT (Masuk)' : 'WITHDRAW (Keluar)';
            $icon = $is_deposit ? '➕' : '➖';
            $employee_name = $decode($decoded_data['employee_name'] ?? 'N/A');
            $product_list = $decoded_data['product_list'] ?? [];

            $embed['title'] = "{$icon} Transaksi Stok {$stock_type_name}: {$action_text}!";
            $embed['description'] = "**{$employee_name}** telah melakukan transaksi stok {$stock_type_name}.";
            $embed['color'] = $is_deposit ? $colors['success'] : $colors['danger'];
            
            $fields = [
                ['name' => 'Dilakukan Oleh', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Waktu', 'value' => date('H:i:s'), 'inline' => true],
            ];
            $detail_list = "";
            foreach ($product_list as $product => $qty) {
                if (is_string($product) && !is_array($qty)) {
                     $detail_list .= "- " . str_replace('_', ' ', $product) . " (`{$qty}`)\n";
                }
            }
            $fields[] = ['name' => 'Detail Item:', 'value' => trim($detail_list)];

            $embed['fields'] = $fields;
            break;

        case 'room_booking_submitted':
            $room_id = $decoded_data['room_id'] ?? 'N/A';
            $booking_name = $decoded_data['booking_name'] ?? 'N/A';
            $booking_datetime = $decoded_data['booking_datetime'] ?? 'N/A';
            $embed['title'] = "🛎️ Permintaan Booking Ruangan Baru!";
            $embed['description'] = "Permintaan booking ruangan baru telah diajukan oleh **{$booking_name}**.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Ruangan', 'value' => $room_id, 'inline' => true],
                ['name' => 'Nama Pemesan', 'value' => $booking_name, 'inline' => true],
                ['name' => 'Tanggal & Waktu', 'value' => date('d/m/Y H:i', strtotime($booking_datetime)), 'inline' => false],
            ];
            break;

        case 'booking_status_updated':
            $room_name = $decoded_data['room_name'] ?? 'N/A';
            $booking_name = $decoded_data['booking_name'] ?? 'N/A';
            $admin_name = $decoded_data['admin_name'] ?? 'N/A';
            $action = $decoded_data['action'] ?? 'N/A';

            $status_text = '';
            $icon = '';
            $color = $colors['info'];

            if ($action === 'approve_booking') {
                $status_text = 'disetujui';
                $icon = '✅';
                $color = $colors['success'];
            } elseif ($action === 'decline_booking') {
                $status_text = 'ditolak';
                $icon = '❌';
                $color = $colors['danger'];
            } elseif ($action === 'mark_as_used') {
                $status_text = 'selesai digunakan';
                $status_icon = '✔️';
                $color = $colors['info'];
            }

            $embed['title'] = "{$status_icon} Status Booking Diperbarui!";
            $embed['description'] = "Booking ruangan **{$room_name}** dari **{$booking_name}** telah **{$status_text}** oleh **{$admin_name}**.";
            $embed['color'] = $color;
            $embed['fields'] = [
                ['name' => 'Ruangan', 'value' => $room_name, 'inline' => true],
                ['name' => 'Pemesan', 'value' => $booking_name, 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $admin_name, 'inline' => true],
            ];
            break;

        case 'payment_status_updated':
            $room_name = $decoded_data['room_name'] ?? 'N/A';
            $booking_name = $decoded_data['booking_name'] ?? 'N/A';
            $admin_name = $decoded_data['admin_name'] ?? 'N/A';
            $action = $decoded_data['action'] ?? 'N/A';
            
            $status_text = '';
            $status_icon = '';
            $color = $colors['info'];
            
            if ($action === 'mark_dp_paid') {
                $status_text = 'Pembayaran DP telah lunas';
                $status_icon = '💰';
                $color = $colors['warning'];
            } elseif ($action === 'mark_full_paid') {
                $status_text = 'Pembayaran penuh telah lunas';
                $status_icon = '✅';
                $color = $colors['success'];
            }
            
            $embed['title'] = "{$status_icon} Status Pembayaran Booking Diperbarui!";
            $embed['description'] = "Status pembayaran untuk booking ruangan **{$room_name}** dari **{$booking_name}** telah diperbarui menjadi **{$status_text}** oleh **{$admin_name}**.";
            $embed['color'] = $color;
            $embed['fields'] = [
                ['name' => 'Ruangan', 'value' => $room_name, 'inline' => true],
                ['name' => 'Pemesan', 'value' => $booking_name, 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Status Terbaru', 'value' => $status_text, 'inline' => false],
            ];
            break;
            
        case 'duty_log_deleted': 
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $admin_name = $decoded_data['admin_name'] ?? 'N/A';
            $duty_start_time = date('d/m/Y H:i', strtotime($decoded_data['duty_start'] ?? ''));
            $duty_end_time = ($decoded_data['duty_end'] ?? null) ? date('H:i', strtotime($decoded_data['duty_end'])) : 'Belum Selesai';
            $duration_display = formatDuration($decoded_data['duration_minutes'] ?? 0);

            $embed['title'] = "🗑️ Log Jam Kerja Dihapus!";
            $embed['description'] = "Log jam kerja anggota **{$employee_name}** telah dihapus oleh **{$admin_name}**.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Dihapus Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Tanggal & Waktu Mulai', 'value' => $duty_start_time, 'inline' => false],
                ['name' => 'Waktu Selesai (estimasi)', 'value' => $duty_end_time, 'inline' => true],
                ['name' => 'Durasi (estimasi)', 'value' => $duration_display, 'inline' => true],
                ['name' => 'Saran', 'value' => "Mohon informasikan anggota tersebut untuk mengajukan `Input Jam Manual` jika periode ini perlu dicatat ulang.", 'inline' => false]
            ];
            break;

        case 'warning_letter_deleted': 
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $admin_name = $decoded_data['admin_name'] ?? 'N/A';
            $embed['title'] = "🗑️ Surat Peringatan Dihapus!";
            $embed['description'] = "Surat Peringatan untuk **{$employee_name}** telah dihapus oleh **{$admin_name}**.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Dihapus Oleh', 'value' => $admin_name, 'inline' => true]
            ];
            break;

        case 'warning_letter_issued': 
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $admin_name = $decoded_data['admin_name'] ?? 'N/A';
            $sp_type = $decoded_data['sp_type'] ?? 'N/A';
            $reason = $decoded_data['reason'] ?? 'N/A';
            $embed['title'] = "⚠️ Surat Peringatan Dikeluarkan!";
            $embed['description'] = "Surat Peringatan **{$sp_type}** untuk **{$employee_name}** telah dikeluarkan oleh **{$admin_name}**.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Tipe SP', 'value' => $sp_type, 'inline' => true],
                ['name' => 'Dikeluarkan Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Alasan', 'value' => $reason, 'inline' => false]
            ];
            break;

        case 'new_employee_request_submitted':
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $new_employee_name = $decoded_data['new_employee_name'] ?? 'N/A';
            $requested_role = $decoded_data['requested_role'] ?? 'N/A';
            $embed['title'] = "➕ Permintaan Anggota Baru!";
            $embed['description'] = "Permintaan untuk menambahkan anggota baru **{$new_employee_name}** ({$requested_role}) telah diajukan oleh **{$employee_name}**.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Diajukan Oleh', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Nama Anggota Baru', 'value' => $new_employee_name, 'inline' => true],
                ['name' => 'Jabatan', 'value' => getRoleDisplayName($requested_role), 'inline' => true],
            ];
            break;

        case 'password_reset_request_submitted':
            $employee_name = $decoded_data['employee_name'] ?? 'N/A';
            $target_employee_name = $decoded_data['target_employee_name'] ?? 'N/A';
            $reset_type_text = ($decoded_data['reset_type'] ?? '') === 'new' ? 'Kata Sandi Baru' : 'Kata Sandi Default';
            $embed['title'] = "🔄 Permintaan Reset Kata Sandi!";
            $embed['description'] = "Permintaan reset kata sandi untuk **{$target_employee_name}** telah diajukan oleh **{$employee_name}**.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Diajukan Oleh', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Untuk Anggota', 'value' => $target_employee_name, 'inline' => true],
                ['name' => 'Tipe Reset', 'value' => $reset_type_text, 'inline' => true],
            ];
            break;
            
        case 'salary_paid_single':
        case 'salary_unpaid_single':
            $employee_name = $decode($decoded_data['employee_name'] ?? 'N/A');
            $status_text = $decode($decoded_data['status'] ?? 'N/A');
            $admin_name = $decode($decoded_data['admin_name'] ?? 'N/A');
            
            $embed['title'] = "💸 Status Gaji Diperbarui!";
            $embed['description'] = "Gaji **{$employee_name}** diubah menjadi **{$status_text}** oleh **{$admin_name}**.";
            $embed['color'] = $type === 'salary_paid_single' ? $colors['success'] : $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Status Baru', 'value' => $status_text, 'inline' => true],
                ['name' => 'Admin', 'value' => $admin_name, 'inline' => true],
            ];
            break;
            
        case 'salary_unpaid_all':
            $admin_name = $decode($decoded_data['admin_name'] ?? 'N/A');
            $embed['title'] = "⚠️ Reset Gaji Massal!";
            $embed['description'] = "Semua status pembayaran gaji anggota **Direset** menjadi **Belum Dibayar** oleh **{$admin_name}**.";
            $embed['color'] = $colors['salary_unpaid_all'];
            $embed['fields'] = [
                ['name' => 'Dilakukan Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Status Target', 'value' => 'Belum Dibayar', 'inline' => true],
            ];
            break;
            
        case 'daily_absent_recap':
            $absent_list = $decoded_data['absent_list'] ?? [];
            $embed['title'] = "🚨 Rekap Absensi (Absen > 3 Hari Beruntun)";
            $embed['description'] = "Berikut adalah anggota yang tercatat **Absen** atau **Tanpa Keterangan Izin** lebih dari 3 hari dalam minggu ini:";
            $embed['color'] = $colors['daily_absent_recap'];
            
            if (empty($absent_list)) {
                $embed['description'] = "Semua anggota tercatat hadir atau memiliki cuti yang disetujui. Pertahankan kinerja baik!";
                $embed['color'] = $colors['success'];
            } else {
                $list_value = "";
                foreach ($absent_list as $employee_name) {
                    $list_value .= "- **{$employee_name}**\n";
                }
                $embed['fields'][] = [
                    'name' => 'Daftar Anggota Bermasalah:', 
                    'value' => trim($list_value), 
                    'inline' => false
                ];
                $embed['fields'][] = [
                    'name' => 'Tindakan', 
                    'value' => "Mohon hubungi anggota-anggota di atas untuk mengajukan cuti atau berikan surat peringatan (SP) sesuai kebijakan HR.", 
                    'inline' => false
                ];
            }
            break;
            
        case 'daily_duty_recap':
            $duty_list = $decoded_data['duty_list'] ?? [];
            $embed['title'] = "⏱️ Rekap Total Jam Duty Anggota";
            $embed['description'] = "Berikut adalah total waktu duty semua anggota (diurutkan dari terlama):";
            $embed['color'] = $colors['daily_duty_recap'];

            if (empty($duty_list)) {
                $embed['description'] = "Tidak ada anggota yang memiliki jam duty yang telah diselesaikan (*completed*).";
            } else {
                $list_value = "";
                foreach ($duty_list as $item) {
                    $list_value .= "• **{$item['name']}**: `{$item['duration']}`\n";
                }
                $embed['fields'][] = [
                    'name' => 'Nama Anggota & Total Waktu Duty',
                    'value' => trim($list_value),
                    'inline' => false
                ];
            }
            break;
            
        default:
            // Fallback for unrecognized messages
            $embed['title'] = "ℹ️ Notifikasi Umum";
            $embed['description'] = $decode(is_array($data) ? json_encode($data) : $data);
            $embed['color'] = $colors['info'];
            break;
    }
    
    // Payload akhir untuk Discord Webhook
    $discord_payload = json_encode([
        'username' => $username,
        'avatar_url' => $avatar_url,
        'embeds' => [$embed],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => $discord_payload,
        ],
    ];

    // Kirim notifikasi ke setiap URL webhook yang relevan
    foreach ($webhooks_to_send as $webhook_url) {
        $context = stream_context_create($options);
        @file_get_contents($webhook_url, false, $context);
    }
}