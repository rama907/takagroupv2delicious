<?php
require_once 'config.php';

// PENTING: Aktifkan ini untuk debugging. Pastikan untuk menonaktifkan atau membatasinya di produksi.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser(); // Pastikan user selalu diambil di awal

// Ambil jumlah permohonan pending
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;
$message_type = 'success'; // Default type for success messages

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id <= 0) {
        $error = "ID permohonan tidak valid!";
        $message_type = 'error';
    } else {
        switch ($action) {
            case 'approve_leave':
            case 'reject_leave':
                $table = 'leave_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_leave') ? 'approved' : 'rejected';
                $notification_prefix = "Permohonan cuti";
                $success_msg_prefix = "Permohonan cuti dari ";
                $error_msg_prefix = "permohonan cuti";
                break;

            case 'approve_resignation':
            case 'reject_resignation':
                $table = 'resignation_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_resignation') ? 'approved' : 'rejected';
                $notification_prefix = "Permohonan resign";
                $success_msg_prefix = "Permohonan resign dari ";
                $error_msg_prefix = "permohonan resign";
                break;

            case 'approve_manual_duty':
            case 'reject_manual_duty':
                $table = 'manual_duty_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_manual_duty') ? 'approved' : 'rejected';
                $notification_prefix = "Input jam manual";
                $success_msg_prefix = "Permohonan input jam manual dari ";
                $error_msg_prefix = "permohonan input jam manual";
                break;

            case 'approve_add_employee':
            case 'reject_add_employee':
                $table = 'add_employee_requests';
                $employee_field = 'employee_name'; // Perhatikan, ini nama, bukan id
                $status_field = 'status';
                $action_type = ($action === 'approve_add_employee') ? 'approved' : 'rejected';
                $notification_prefix = "Permintaan anggota baru";
                $success_msg_prefix = "Permintaan anggota baru dari ";
                $error_msg_prefix = "permintaan anggota baru";
                break;
            
            case 'approve_password_reset':
            case 'reject_password_reset':
                $table = 'password_reset_requests';
                $employee_field = 'employee_id';
                $status_field = 'status';
                $action_type = ($action === 'approve_password_reset') ? 'approved' : 'rejected';
                $notification_prefix = "Permintaan reset password";
                $success_msg_prefix = "Permintaan reset password dari ";
                $error_msg_prefix = "permintaan reset password";
                break;
                
            default:
                $error = "Aksi tidak dikenal.";
                $message_type = 'error';
                break;
        }

        if (isset($table) && !$error) { // Lanjutkan hanya jika tabel terdefinisi dan tidak ada error awal
            // Pertama, cek apakah permohonan ada dan statusnya pending
            $check_stmt_sql = "SELECT r.*, e.name as employee_name FROM {$table} r JOIN employees e ON r.employee_id = e.id WHERE r.id = ? AND r.status = 'pending'";

            // Khusus untuk add_employee_requests karena employee_id tidak ada di tabel ini
            if ($table === 'add_employee_requests') {
                 $check_stmt_sql = "SELECT * FROM {$table} WHERE id = ? AND status = 'pending'";
            }
            
            $check_stmt = $conn->prepare($check_stmt_sql);
            if (!$check_stmt) {
                $error = "Gagal menyiapkan query cek {$error_msg_prefix}: " . $conn->error;
                $message_type = 'error';
            } else {
                $check_stmt->bind_param("i", $request_id);
                $check_stmt->execute();
                $request_data = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($request_data) {
                    $conn->begin_transaction(); // Mulai transaksi

                    try {
                        if ($action_type === 'approved') {
                            if ($table === 'manual_duty_requests') {
                                // Logika khusus untuk approve manual duty
                                $start_datetime_str = $request_data['duty_date'] . ' ' . $request_data['start_time'];
                                $end_datetime_str = $request_data['duty_date'] . ' ' . $request_data['end_time'];
                                
                                $start_timestamp = strtotime($start_datetime_str);
                                $end_timestamp = strtotime($end_datetime_str);
                                
                                if ($end_timestamp <= $start_timestamp) {
                                    $end_timestamp += 24 * 60 * 60; 
                                    $end_datetime_str = date('Y-m-d H:i:s', $end_timestamp);
                                }
                                
                                $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                                
                                if ($duration_minutes < 1 || $duration_minutes > (24 * 60)) { // Tambahan validasi durasi
                                    throw new Exception("Durasi jam manual tidak valid. Pastikan durasi antara 1 menit dan 24 jam.");
                                }

                                $insert_duty_stmt = $conn->prepare("
                                    INSERT INTO duty_logs (employee_id, duty_start, duty_end, duration_minutes, is_manual, approved_by, status)
                                    VALUES (?, ?, ?, ?, 1, ?, 'completed')
                                ");
                                if (!$insert_duty_stmt) {
                                    throw new Exception("Gagal menyiapkan query insert duty logs: " . $conn->error);
                                }
                                $insert_duty_stmt->bind_param("issii", $request_data['employee_id'], $start_datetime_str, $end_datetime_str, $duration_minutes, $user['id']);
                                
                                if (!$insert_duty_stmt->execute()) {
                                    throw new Exception("Gagal menambahkan ke duty logs: " . $insert_duty_stmt->error);
                                }
                                $insert_duty_stmt->close();
                                
                            } elseif ($table === 'add_employee_requests') {
                                // Logika khusus untuk approve permintaan anggota baru
                                $insert_employee_stmt = $conn->prepare("
                                    INSERT INTO employees (name, role, password)
                                    VALUES (?, ?, ?)
                                ");
                                if (!$insert_employee_stmt) {
                                    throw new Exception("Gagal menyiapkan query tambah anggota: " . $conn->error);
                                }
                                $insert_employee_stmt->bind_param("sss", $request_data['employee_name'], $request_data['requested_role'], $request_data['requested_password']);
                                if (!$insert_employee_stmt->execute()) {
                                    throw new Exception("Gagal menambahkan anggota baru: " . $insert_employee_stmt->error);
                                }
                                $insert_employee_stmt->close();

                            } elseif ($table === 'password_reset_requests') {
                                // Logika khusus untuk approve permintaan reset password
                                $update_password_stmt = $conn->prepare("
                                    UPDATE employees SET password = ? WHERE id = ?
                                ");
                                if (!$update_password_stmt) {
                                    throw new Exception("Gagal menyiapkan query update password: " . $conn->error);
                                }
                                $update_password_stmt->bind_param("si", $request_data['requested_password'], $request_data['employee_id']);
                                if (!$update_password_stmt->execute()) {
                                    throw new Exception("Gagal mengubah password anggota: " . $update_password_stmt->error);
                                }
                                $update_password_stmt->close();
                            }
                        }

                        // Update status permohonan di tabel utama
                        $update_stmt_sql = "UPDATE {$table} SET {$status_field} = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND {$status_field} = 'pending'";
                        $update_stmt = $conn->prepare($update_stmt_sql);
                        if (!$update_stmt) {
                            throw new Exception("Gagal menyiapkan query update status {$error_msg_prefix}: " . $conn->error);
                        }
                        $update_stmt->bind_param("sii", $action_type, $user['id'], $request_id);
                        
                        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                            $conn->commit(); // Commit transaksi
                            $success = $success_msg_prefix . htmlspecialchars($request_data['employee_name'] ?? $request_data['employee_name']) . " berhasil di" . ($action_type === 'approved' ? "setujui" : "tolak") . "!";
                            
                            // Tentukan tipe pesan berdasarkan aksi
                            if ($action_type === 'rejected') {
                                $message_type = 'error'; // Ubah menjadi 'error' (merah)
                            } else {
                                $message_type = 'success'; // Tetap 'success' (hijau)
                            }
                            
                            // Kirim notifikasi Discord
                            if ($table === 'add_employee_requests') {
                                sendDiscordNotification([
                                    'employee_name' => getEmployeeNameById($request_data['requested_by']),
                                    'target_employee_name' => $request_data['employee_name'],
                                    'status' => $action_type,
                                    'approved_by_name' => $user['name'],
                                ], 'request_status_update');
                            } elseif ($table === 'password_reset_requests') {
                                sendDiscordNotification([
                                    'employee_name' => getEmployeeNameById($request_data['requested_by']),
                                    'target_employee_name' => getEmployeeNameById($request_data['employee_id']),
                                    'status' => $action_type,
                                    'approved_by_name' => $user['name'],
                                ], 'request_status_update');
                            } else {
                                sendDiscordNotification([
                                    'employee_name' => $request_data['employee_name'],
                                    'request_type' => ($table === 'leave_requests' ? 'Cuti' : ($table === 'resignation_requests' ? 'Resign' : 'Input Jam Manual')),
                                    'status' => $action_type,
                                    'approved_by_name' => $user['name'],
                                ], 'request_status_update');
                            }

                        } else {
                            throw new Exception("Gagal mengupdate status {$error_msg_prefix}! Mungkin sudah diproses sebelumnya. Error: " . $update_stmt->error);
                        }
                        $update_stmt->close();

                    } catch (Exception $e) {
                        $conn->rollback(); // Rollback transaksi jika ada error
                        $error = "Terjadi kesalahan saat memproses {$error_msg_prefix}: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $error = "Permohonan tidak ditemukan atau sudah diproses!";
                    $message_type = 'error';
                }
            }
        }
    }
}


// --- Pengambilan Data Permohonan untuk Tampilan ---
// Pastikan semua pengambilan data ditangani dengan benar untuk menghindari 'Call to a member function fetch_all() on bool'

// Get pending leave requests
$leave_requests = [];
$query_leave = "
    SELECT lr.*, e.name as employee_name, e.role as employee_role
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    WHERE lr.status = 'pending'
    ORDER BY lr.created_at ASC
";
$result_leave = $conn->query($query_leave);
if ($result_leave === false) {
    $error = "Gagal mengambil permohonan cuti: " . $conn->error;
    $message_type = 'error';
} else {
    $leave_requests = $result_leave->fetch_all(MYSQLI_ASSOC);
    $result_leave->free(); // Bebaskan hasil query
}

// Get pending resignation requests
$resignation_requests = [];
$query_resignation = "
    SELECT rr.*, e.name as employee_name, e.role as employee_role
    FROM resignation_requests rr
    JOIN employees e ON rr.employee_id = e.id
    WHERE rr.status = 'pending'
    ORDER BY rr.created_at ASC
";
$result_resignation = $conn->query($query_resignation);
if ($result_resignation === false) {
    $error = "Gagal mengambil permohonan resign: " . $conn->error;
    $message_type = 'error';
} else {
    $resignation_requests = $result_resignation->fetch_all(MYSQLI_ASSOC);
    $result_resignation->free(); // Bebaskan hasil query
}

// Get pending manual duty requests
$manual_duty_requests = [];
$query_manual = "
    SELECT mdr.*, e.name as employee_name, e.role as employee_role
    FROM manual_duty_requests mdr
    JOIN employees e ON mdr.employee_id = e.id
    WHERE mdr.status = 'pending'
    ORDER BY mdr.created_at ASC
";
$result_manual = $conn->query($query_manual);
if ($result_manual === false) {
    $error = "Gagal mengambil permohonan input jam manual: " . $conn->error;
    $message_type = 'error';
} else {
    $manual_duty_requests = $result_manual->fetch_all(MYSQLI_ASSOC);
    $result_manual->free(); // Bebaskan hasil query
}

// Get pending new employee requests
$new_employee_requests = [];
$query_new_employee = "
    SELECT ae.*, e.name AS requested_by_name
    FROM add_employee_requests ae
    LEFT JOIN employees e ON ae.requested_by = e.id
    WHERE ae.status = 'pending'
    ORDER BY ae.created_at ASC
";
$result_new_employee = $conn->query($query_new_employee);
if ($result_new_employee === false) {
    $error = "Gagal mengambil permohonan anggota baru: " . $conn->error;
    $message_type = 'error';
} else {
    $new_employee_requests = $result_new_employee->fetch_all(MYSQLI_ASSOC);
    $result_new_employee->free();
}

// Get pending password reset requests
$password_reset_requests = [];
$query_password_reset = "
    SELECT pr.*, e_req.name AS requested_by_name, e_target.name AS employee_name
    FROM password_reset_requests pr
    LEFT JOIN employees e_req ON pr.requested_by = e_req.id
    LEFT JOIN employees e_target ON pr.employee_id = e_target.id
    WHERE pr.status = 'pending'
    ORDER BY pr.created_at ASC
";
$result_password_reset = $conn->query($query_password_reset);
if ($result_password_reset === false) {
    $error = "Gagal mengambil permohonan reset password: " . $conn->error;
    $message_type = 'error';
} else {
    $password_reset_requests = $result_password_reset->fetch_all(MYSQLI_ASSOC);
    $result_password_reset->free();
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📋</span>
                    Permohonan
                </h1>
                <p>Kelola semua permohonan dari anggota</p>
            </div>

            <?php 
            // Menampilkan pesan berdasarkan message_type
            if (isset($success) && $message_type === 'success'): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php elseif (isset($success) && $message_type === 'error'): // Jika success tapi type-nya error (misal penolakan) ?>
                <div class="error-message">❌ <?= htmlspecialchars($success) ?></div>
            <?php elseif (isset($error)): // Jika ada error (pesan kegagalan) ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="requests-tabs">
                <button class="tab-button" onclick="showTab('leave')">
                    Cuti (<?= count($leave_requests) ?>)
                </button>
                <button class="tab-button" onclick="showTab('resignation')">
                    Resign (<?= count($resignation_requests) ?>)
                </button>
                <button class="tab-button active" onclick="showTab('manual')">
                    Input Manual (<?= count($manual_duty_requests) ?>)
                </button>
                <button class="tab-button" onclick="showTab('new-employee')">
                    Anggota Baru (<?= count($new_employee_requests) ?>)
                </button>
                <button class="tab-button" onclick="showTab('password-reset')">
                    Reset Password (<?= count($password_reset_requests) ?>)
                </button>
            </div>

            <div id="leave-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Permohonan Cuti</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($leave_requests)): ?>
                            <div class="no-data">Tidak ada permohonan cuti yang pending</div>
                        <?php else: ?>
                            <?php foreach ($leave_requests as $request): ?>
                            <div class="request-item">
                                <div class="request-header">
                                    <div class="employee-info">
                                        <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                        <span class="role-badge role-<?= $request['employee_role'] ?>"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                    </div>
                                    <div class="request-dates">
                                        <?= date('d/m/Y', strtotime($request['start_date'])) ?> - 
                                        <?= date('d/m/Y', strtotime($request['end_date'])) ?>
                                    </div>
                                </div>
                                
                                <?php if ($request['reason_ooc']): ?>
                                <div class="request-reason">
                                    <strong>Alasan OOC:</strong> <?= htmlspecialchars($request['reason_ooc']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($request['reason_ic']): ?>
                                <div class="request-reason">
                                    <strong>Alasan IC:</strong> <?= htmlspecialchars($request['reason_ic']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="request-meta">
                                    Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                </div>
                                
                                <div class="request-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui permohonan cuti ini?')">
                                        <input type="hidden" name="action" value="approve_leave">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan cuti ini?')">
                                        <input type="hidden" name="action" value="reject_leave">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="resignation-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Permohonan Resign</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($resignation_requests)): ?>
                            <div class="no-data">Tidak ada permohonan resign yang pending</div>
                        <?php else: ?>
                            <?php foreach ($resignation_requests as $request): ?>
                            <div class="request-item">
                                <div class="request-header">
                                    <div class="employee-info">
                                        <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                        <span class="role-badge role-<?= $request['employee_role'] ?>"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                    </div>
                                    <div class="request-dates">
                                        Tanggal Resign: <?= date('d/m/Y', strtotime($request['start_date'])) ?>
                                    </div>
                                </div>
                                
                                <div class="request-details">
                                    <div><strong>Passport:</strong> <?= htmlspecialchars($request['passport']) ?></div>
                                    <div><strong>CID:</strong> <?= htmlspecialchars($request['cid']) ?></div>
                                </div>
                                
                                <?php if ($request['reason_ooc']): ?>
                                <div class="request-reason">
                                    <strong>Alasan OOC:</strong> <?= htmlspecialchars($request['reason_ooc']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($request['reason_ic']): ?>
                                <div class="request-reason">
                                    <strong>Alasan IC:</strong> <?= htmlspecialchars($request['reason_ic']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="request-meta">
                                    Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                </div>
                                
                                <div class="request-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui permohonan resign ini?')">
                                        <input type="hidden" name="action" value="approve_resignation">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan resign ini?')">
                                        <input type="hidden" name="action" value="reject_resignation">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="manual-tab" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3>Permohonan Input Jam Manual</h3>
                        </div>
                        <div class="card-content">
                            <?php if (empty($manual_duty_requests)): ?>
                                <div class="no-data">Tidak ada permohonan input jam manual yang pending</div>
                            <?php else: ?>
                                <?php foreach ($manual_duty_requests as $request): ?>
                                <?php
                                // Calculate duration for display
                                $start_timestamp = strtotime($request['start_time']);
                                $end_timestamp = strtotime($request['end_time']);
                                if ($end_timestamp <= $start_timestamp) {
                                    $end_timestamp += 24 * 60 * 60;
                                }
                                $duration_minutes = ($end_timestamp - $start_timestamp) / 60;
                                $duration_display = formatDuration($duration_minutes);
                                $is_overnight = $end_timestamp > strtotime($request['start_time']) + 12 * 60 * 60;
                                ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <div class="employee-info">
                                            <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                            <span class="role-badge role-<?= $request['employee_role'] ?>"><?= getRoleDisplayName($request['employee_role']) ?></span>
                                        </div>
                                        <div class="request-dates">
                                            <?= date('d/m/Y', strtotime($request['duty_date'])) ?>
                                            (<?= date('H:i', strtotime($request['start_time'])) ?> - <?= date('H:i', strtotime($request['end_time'])) ?>)
                                            <br><small><strong>Durasi:</strong> <?= $duration_display ?><?= $is_overnight ? ' (shift malam)' : '' ?></small>
                                        </div>
                                    </div>
                                    
                                    <div class="request-reason">
                                        <strong>Alasan:</strong> <?= htmlspecialchars($request['reason']) ?>
                                    </div>
                                    
                                    <div class="request-meta">
                                        Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                    </div>
                                    
                                    <div class="request-actions">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui dan menambahkan jam duty ini?\nDurasi: <?= $duration_display ?><?= $is_overnight ? ' (shift malam)' : '' ?>')">
                                            <input type="hidden" name="action" value="approve_manual_duty">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan input jam manual ini?')">
                                            <input type="hidden" name="action" value="reject_manual_duty">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="new-employee-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Permintaan Anggota Baru</h3>
                        </div>
                        <div class="card-content">
                            <?php if (empty($new_employee_requests)): ?>
                                <div class="no-data">Tidak ada permohonan anggota baru yang pending</div>
                            <?php else: ?>
                                <?php foreach ($new_employee_requests as $request): ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <div class="employee-info">
                                            <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                            <span class="role-badge role-<?= $request['requested_role'] ?>"><?= getRoleDisplayName($request['requested_role']) ?></span>
                                        </div>
                                    </div>
                                    <div class="request-details">
                                        <div><strong>Diajukan Oleh:</strong> <?= htmlspecialchars($request['requested_by_name']) ?></div>
                                        <div><strong>Password Dibuat:</strong> <?= empty($request['requested_password']) ? 'Default' : 'Custom' ?></div>
                                    </div>
                                    <div class="request-meta">
                                        Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                    </div>
                                    <div class="request-actions">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui permohonan anggota baru ini?')">
                                            <input type="hidden" name="action" value="approve_add_employee">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan anggota baru ini?')">
                                            <input type="hidden" name="action" value="reject_add_employee">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="password-reset-tab" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>Permintaan Reset Kata Sandi</h3>
                        </div>
                        <div class="card-content">
                            <?php if (empty($password_reset_requests)): ?>
                                <div class="no-data">Tidak ada permohonan reset kata sandi yang pending</div>
                            <?php else: ?>
                                <?php foreach ($password_reset_requests as $request): ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <div class="employee-info">
                                            <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                            <span class="role-badge role-<?= $request['reset_type'] ?>"><?= $request['reset_type'] == 'default' ? 'Reset Default' : 'Password Baru' ?></span>
                                        </div>
                                    </div>
                                    <div class="request-details">
                                        <div><strong>Diajukan Oleh:</strong> <?= htmlspecialchars($request['requested_by_name']) ?></div>
                                        <div><strong>Tipe Reset:</strong> <?= $request['reset_type'] == 'default' ? 'Default' : 'Diajukan' ?></div>
                                    </div>
                                    <div class="request-meta">
                                        Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                    </div>
                                    <div class="request-actions">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menyetujui permohonan reset kata sandi ini?')">
                                            <input type="hidden" name="action" value="approve_password_reset">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menolak permohonan reset kata sandi ini?')">
                                            <input type="hidden" name="action" value="reject_password_reset">
                                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <script src="script.js"></script>
        <script>
            function showTab(tabName) {
                // Hide all tab contents
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Remove active class from all tab buttons
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.classList.remove('active');
                });
                
                // Show selected tab content
                document.getElementById(tabName + '-tab').classList.add('active');
                
                // Add active class to clicked button
                event.target.classList.add('active');
            }
            
            // Prevent double submission and show loading state
            document.addEventListener('DOMContentLoaded', function() {
                const forms = document.querySelectorAll('form');
                
                forms.forEach(form => {
                    form.addEventListener('submit', function(e) {
                        const submitBtn = this.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            // Disable button to prevent double submission
                            submitBtn.disabled = true;
                            
                            // Show loading state
                            const originalText = submitBtn.textContent;
                            submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';
                            
                            // Re-enable after 3 seconds as fallback
                            setTimeout(() => {
                                submitBtn.disabled = false;
                                submitBtn.textContent = originalText;
                            }, 3000);
                        }
                    });
                });
                
                // Auto-refresh page after successful action (optional)
                const successMessage = document.querySelector('.success-message');
                if (successMessage) {
                    setTimeout(() => {
                        // Fade out success message
                        successMessage.style.opacity = '0.5';
                    }, 3000);
                }
            });
        </script>
    </body>
    </html>