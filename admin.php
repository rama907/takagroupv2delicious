<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add_employee':
            $name = $_POST['name'] ?? '';
            $role = $_POST['role'] ?? '';
            $password_input = $_POST['password'] ?? '';
            
            // Use default password if empty, otherwise use provided password
            $password_to_hash = empty($password_input) ? 'password' : $password_input;
            $password = password_hash($password_to_hash, PASSWORD_DEFAULT);
            
            if ($name && $role) {
                $stmt = $conn->prepare("INSERT INTO employees (name, role, password) VALUES (?, ?, ?)");
                if (!$stmt) {
                    $error = "Gagal menyiapkan query tambah anggota: " . $conn->error;
                } else {
                    $stmt->bind_param("sss", $name, $role, $password);
                    if ($stmt->execute()) {
                        $success = "Anggota baru berhasil ditambahkan! Password default: 'password'";
                        // PERBAIKAN: Kirim data sebagai array asosiatif
                        sendDiscordNotification([
                            'action_type' => 'add_employee', // Tipe aksi baru untuk pelacakan
                            'target_employee_name' => $name,
                            'role' => $role,
                            'admin_name' => $user['name']
                        ], 'admin_employee_action'); // Gunakan tipe notifikasi yang benar
                    } else {
                        $error = "Gagal menambahkan anggota baru: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                $error = "Nama dan jabatan harus diisi!";
            }
            break;

        case 'update_role':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $new_role = $_POST['new_role'] ?? '';
            
            if ($employee_id && $new_role) {
                // Get employee name and old role for feedback
                $stmt = $conn->prepare("SELECT name, role FROM employees WHERE id = ? AND status = 'active'");
                if (!$stmt) {
                    $error = "Gagal menyiapkan query cek anggota: " . $conn->error;
                } else {
                    $stmt->bind_param("i", $employee_id);
                    $stmt->execute();
                    $employee_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($employee_data) {
                        // Check if the new role is different from current role
                        if ($employee_data['role'] !== $new_role) {
                            $stmt_update = $conn->prepare("UPDATE employees SET role = ? WHERE id = ? AND status = 'active'");
                            if (!$stmt_update) {
                                $error = "Gagal menyiapkan query update jabatan: " . $conn->error;
                            } else {
                                $stmt_update->bind_param("si", $new_role, $employee_id);
                                if ($stmt_update->execute() && $stmt_update->affected_rows > 0) {
                                    $success = "Jabatan " . htmlspecialchars($employee_data['name']) . " berhasil diubah dari " . getRoleDisplayName($employee_data['role']) . " menjadi " . getRoleDisplayName($new_role) . "!";
                                    // PERBAIKAN: Kirim data sebagai array asosiatif
                                    sendDiscordNotification([
                                        'action_type' => 'update_role',
                                        'target_employee_name' => $employee_data['name'],
                                        'old_value' => $employee_data['role'],
                                        'new_value' => $new_role,
                                        'admin_name' => $user['name']
                                    ], 'admin_employee_action'); // Gunakan tipe notifikasi yang benar
                                } else {
                                    $error = "Gagal mengubah jabatan: " . $stmt_update->error;
                                }
                                $stmt_update->close();
                            }
                        } else {
                            $error = "Jabatan yang dipilih sama dengan jabatan saat ini!";
                        }
                    } else {
                        $error = "Anggota tidak ditemukan atau tidak aktif!";
                    }
                }
            } else {
                $error = "Data tidak lengkap untuk mengubah jabatan!";
            }
            break;

        case 'deactivate_employee':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            
            if ($employee_id && $employee_id != $user['id']) {
                // Get employee name for feedback
                $stmt = $conn->prepare("SELECT name, is_on_duty FROM employees WHERE id = ? AND status = 'active'"); // Tambahkan is_on_duty ke SELECT
                if (!$stmt) {
                    $error = "Gagal menyiapkan query cek anggota: " . $conn->error;
                } else {
                    $stmt->bind_param("i", $employee_id);
                    $stmt->execute();
                    $employee_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($employee_data) {
                        $conn->begin_transaction(); // Mulai transaksi
                        try {
                            // Cek apakah karyawan sedang On Duty dan set Off Duty otomatis
                            if (isset($employee_data['is_on_duty']) && $employee_data['is_on_duty']) {
                                $stmt_get_active_log = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
                                if (!$stmt_get_active_log) {
                                    throw new Exception("Gagal menyiapkan query ambil log aktif: " . $conn->error);
                                }
                                $stmt_get_active_log->bind_param("i", $employee_id);
                                $stmt_get_active_log->execute();
                                $active_log = $stmt_get_active_log->get_result()->fetch_assoc();
                                $stmt_get_active_log->close();

                                if ($active_log) {
                                    $duty_start_dt = new DateTime($active_log['duty_start']);
                                    $now_dt = new DateTime();
                                    $duration_minutes = ($now_dt->getTimestamp() - $duty_start_dt->getTimestamp()) / 60;
                                    
                                    $stmt_update_log = $conn->prepare("UPDATE duty_logs SET duty_end = NOW(), duration_minutes = ?, status = 'completed', approved_by = ? WHERE id = ?");
                                    if (!$stmt_update_log) {
                                        throw new Exception("Gagal menyiapkan query update log duty: " . $conn->error);
                                    }
                                    $stmt_update_log->bind_param("iii", $duration_minutes, $user['id'], $active_log['id']);
                                    if (!$stmt_update_log->execute()) {
                                        throw new Exception("Gagal mengakhiri log duty aktif: " . $stmt_update_log->error);
                                    }
                                    $stmt_update_log->close();
                                }
                                // Pastikan status is_on_duty di employees juga direset
                                $stmt_reset_duty_status = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
                                if (!$stmt_reset_duty_status) {
                                    throw new Exception("Gagal menyiapkan query reset status duty: " . $conn->error);
                                }
                                $stmt_reset_duty_status->bind_param("i", $employee_id);
                                if (!$stmt_reset_duty_status->execute()) {
                                    throw new Exception("Gagal mereset status duty karyawan: " . $stmt_reset_duty_status->error);
                                }
                                $stmt_reset_duty_status->close();
                            }

                            $stmt_update = $conn->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?");
                            if (!$stmt_update) {
                                throw new Exception("Gagal menyiapkan query nonaktifkan anggota: " . $conn->error);
                            }
                            $stmt_update->bind_param("i", $employee_id);
                            if ($stmt_update->execute()) {
                                $conn->commit();
                                $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil dinonaktifkan!";
                                // PERBAIKAN: Kirim data sebagai array asosiatif
                                sendDiscordNotification([
                                    'action_type' => 'deactivate_employee',
                                    'target_employee_name' => $employee_data['name'],
                                    'admin_name' => $user['name']
                                ], 'admin_employee_action'); // Gunakan tipe notifikasi yang benar
                            } else {
                                throw new Exception("Gagal menonaktifkan anggota: " . $stmt_update->error);
                            }
                            $stmt_update->close();
                            
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Terjadi kesalahan saat menonaktifkan anggota: " . $e->getMessage();
                        }
                    } else {
                        $error = "Anggota tidak ditemukan atau sudah tidak aktif!";
                    }
                }
            } else {
                $error = "Tidak dapat menonaktifkan diri sendiri atau ID tidak valid!";
            }
            break;

        case 'update_discord_id':
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $new_discord_id = trim($_POST['new_discord_id'] ?? '');

            if ($employee_id <= 0) {
                $error = "ID anggota tidak valid!";
            } else {
                // Get employee name and current Discord ID for feedback/notification
                $stmt_get = $conn->prepare("SELECT name, discord_id FROM employees WHERE id = ?");
                $stmt_get->bind_param("i", $employee_id);
                $stmt_get->execute();
                $employee_data = $stmt_get->get_result()->fetch_assoc();
                $stmt_get->close();

                if ($employee_data) {
                    $old_discord_id = $employee_data['discord_id'] ?? '';
                    
                    // Use NULL if the input is empty string, otherwise use the new ID
                    $discord_id_to_save = empty($new_discord_id) ? NULL : $new_discord_id;
                    
                    $stmt_update = $conn->prepare("UPDATE employees SET discord_id = ? WHERE id = ?");
                    if (!$stmt_update) {
                        $error = "Gagal menyiapkan query update Discord ID: " . $conn->error;
                    } else {
                        $stmt_update->bind_param("si", $discord_id_to_save, $employee_id);
                        if ($stmt_update->execute()) {
                            $action_desc = empty($new_discord_id) ? 'dihapus' : (empty($old_discord_id) ? 'ditambahkan' : 'diubah');
                            $success = "Discord ID untuk " . htmlspecialchars($employee_data['name']) . " berhasil {$action_desc}!";
                            
                            sendDiscordNotification([
                                'action_type' => 'update_discord_id',
                                'target_employee_name' => $employee_data['name'],
                                'old_value' => $old_discord_id,
                                'new_value' => $new_discord_id,
                                'admin_name' => $user['name']
                            ], 'admin_employee_action');
                        } else {
                            $error = "Gagal memperbarui Discord ID: " . $stmt_update->error;
                        }
                        $stmt_update->close();
                    }
                } else {
                    $error = "Anggota tidak ditemukan!";
                }
            }
            break;

        default:
            $error = "Aksi tidak dikenal.";
            break;
    }
}


// Get all employees for management
$employees = $conn->query("
    SELECT * FROM employees 
    WHERE status = 'active' 
    ORDER BY 
        CASE role 
            WHEN 'ceo' THEN 1
            WHEN 'direktur' THEN 2
            WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4
            WHEN 'chef' THEN 5
            WHEN 'waiters' THEN 6
            WHEN 'karyawan' THEN 7
            WHEN 'magang' THEN 8
        END,
        name
")->fetch_all(MYSQLI_ASSOC);

// Get all active employees along with their discord_id for the management form (Sorted by missing ID)
$stmt_discord_list = $conn->query("
    SELECT id, name, role, discord_id 
    FROM employees 
    WHERE status = 'active'
    ORDER BY 
        CASE WHEN discord_id IS NULL OR discord_id = '' THEN 0 ELSE 1 END,
        name
");
$discord_management_list = $stmt_discord_list->fetch_all(MYSQLI_ASSOC);


// Get system statistics
$stats = [];
$stats['total_employees'] = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'")->fetch_assoc()['count'];
$stats['on_duty_count'] = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_on_duty = 1")->fetch_assoc()['count'];
$stats['pending_requests'] = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') +
        (SELECT COUNT(*) FROM resignation_requests WHERE status = 'pending') +
        (SELECT COUNT(*) FROM manual_duty_requests WHERE status = 'pending') as count
")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Delicious</title>
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
                    <span class="page-icon">⚙️</span>
                    Admin Panel
                </h1>
                <p>Kelola sistem dan anggota Delicious</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <h3>Total Anggota</h3>
                        <p class="stat-value"><?= $stats['total_employees'] ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🟢</div>
                    <div class="stat-content">
                        <h3>Sedang On Duty</h3>
                        <p class="stat-value"><?= $stats['on_duty_count'] ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-content">
                        <h3>Permohonan Pending</h3>
                        <p class="stat-value"><?= $stats['pending_requests'] ?></p>
                    </div>
                </div>
            </div>

            <div class="admin-tabs">
                <button class="tab-button active" onclick="showAdminTab('employees')">Kelola Anggota</button>
                <button class="tab-button" onclick="showAdminTab('system')">Pengaturan Sistem</button>
            </div>

            <div id="employees-admin-tab" class="tab-content active">
                <div class="content-grid">
                    <div class="card">
                        <div class="card-header">
                            <h3>Tambah Anggota Baru</h3>
                        </div>
                        <div class="card-content">
                            <form method="POST" class="admin-form" id="add_employee_form">
                                <input type="hidden" name="action" value="add_employee">
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="name">Nama</label>
                                        <input type="text" name="name" id="name" class="form-input" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="role">Jabatan</label>
                                        <select name="role" id="role" class="form-select" required>
                                            <option value="">Pilih Jabatan</option>
                                            <option value="ceo">CEO</option>
                                            <option value="direktur">Direktur</option>
                                            <option value="wakil_direktur">Wakil Direktur</option>
                                            <option value="manager">Manager</option>
                                            <option value="chef">Chef</option>
                                            <option value="waiters">Waiters</option>
                                            <option value="karyawan">Karyawan</option>
                                            <option value="magang">Magang</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="password">Password (Opsional)</label>
                                    <input type="password" name="password" id="password" class="form-input" 
                                           placeholder="Kosongkan untuk password default">
                                    <small class="form-help">Jika dikosongkan, password default adalah: <strong>password</strong></small>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Tambah Anggota</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>Daftar Anggota</h3>
                        </div>
                        <div class="card-content">
                            <div class="employees-management-list">
                                <?php foreach ($employees as $employee): ?>
                                <div class="employee-management-item">
                                    <div class="employee-info-section">
                                        <div class="employee-avatar-small">👤</div>
                                        <div class="employee-details">
                                            <h4><?= htmlspecialchars($employee['name']) ?></h4>
                                            <span class="role-badge role-<?= $employee['role'] ?>"><?= getRoleDisplayName($employee['role']) ?></span>
                                            <div class="employee-status-inline">
                                                <span class="status-indicator <?= $employee['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                                <span><?= $employee['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="employee-actions">
                                        <form method="POST" class="role-change-form" id="update_role_form_<?= $employee['id'] ?>" onsubmit="event.preventDefault(); return confirmRoleChange('<?= htmlspecialchars($employee['name']) ?>', this)">
                                            <input type="hidden" name="action" value="update_role">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <div class="role-change-group">
                                                <select name="new_role" class="form-select-small" required>
                                                    <option value="">Ubah Jabatan</option>
                                                    <option value="ceo" <?= $employee['role'] == 'ceo' ? 'selected' : '' ?>>
                                                        CEO <?= $employee['role'] == 'ceo' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="direktur" <?= $employee['role'] == 'direktur' ? 'selected' : '' ?>>
                                                        Direktur <?= $employee['role'] == 'direktur' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="wakil_direktur" <?= $employee['role'] == 'wakil_direktur' ? 'selected' : '' ?>>
                                                        Wakil Direktur <?= $employee['role'] == 'wakil_direktur' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="manager" <?= $employee['role'] == 'manager' ? 'selected' : '' ?>>
                                                        Manager <?= $employee['role'] == 'manager' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="chef" <?= $employee['role'] == 'chef' ? 'selected' : '' ?>>
                                                        Chef <?= $employee['role'] == 'chef' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="waiters" <?= $employee['role'] == 'waiters' ? 'selected' : '' ?>>
                                                        Waiters <?= $employee['role'] == 'waiters' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="karyawan" <?= $employee['role'] == 'karyawan' ? 'selected' : '' ?>>
                                                        Karyawan <?= $employee['role'] == 'karyawan' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                    <option value="magang" <?= $employee['role'] == 'magang' ? 'selected' : '' ?>>
                                                        Magang <?= $employee['role'] == 'magang' ? '(Saat ini)' : '' ?>
                                                    </option>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm">Ubah</button>
                                            </div>
                                        </form>
                                        
                                        <?php if ($employee['id'] != $user['id']): ?>
                                        <form method="POST" class="deactivate-form" id="deactivate_form_<?= $employee['id'] ?>" onsubmit="event.preventDefault(); return confirmDeactivation(this, '<?= htmlspecialchars($employee['name']) ?>')">
                                            <input type="hidden" name="action" value="deactivate_employee">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Nonaktifkan</button>
                                        </form>
                                        <?php else: ?>
                                        <div class="self-indicator">
                                            <small class="text-muted">Anda sendiri</small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="system-admin-tab" class="tab-content">
                <div class="content-grid">
                    <div class="card full-width">
                        <div class="card-header">
                            <h3>Kelola Discord ID Anggota</h3>
                        </div>
                        <div class="card-content">
                            <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                                <strong>Info:</strong> Tambahkan atau perbarui Discord User ID (contoh: 123456789012345678). Anggota yang tidak memiliki ID akan muncul paling atas. Kosongkan ID untuk menghapusnya.
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_discord_id">
                                
                                <div class="form-group">
                                    <label for="employee_id_discord">Pilih Anggota</label>
                                    <select name="employee_id" id="employee_id_discord" class="form-select" required>
                                        <option value="">-- Pilih Anggota --</option>
                                        <?php foreach ($discord_management_list as $emp): ?>
                                            <option value="<?= $emp['id'] ?>">
                                                <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>) 
                                                [ID Saat Ini: <?= empty($emp['discord_id']) ? '❌ Belum Ada' : htmlspecialchars($emp['discord_id']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_discord_id">Discord User ID Baru</label>
                                    <input type="text" name="new_discord_id" id="new_discord_id" class="form-input" 
                                           placeholder="Masukkan Discord ID (Kosongkan untuk menghapus)">
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Simpan Discord ID</button>
                            </form>
                        </div>
                    </div>

                    <div class="card full-width">
                        <div class="card-header">
                            <h3>Pengaturan Sistem Lainnya</h3>
                        </div>
                        <div class="card-content">
                            <div class="system-actions">
                                
                                <div class="action-item">
                                    <div class="action-info">
                                        <h4>Backup Database</h4>
                                        <p>Buat backup database sistem (fitur akan segera tersedia)</p>
                                    </div>
                                    <button class="btn btn-secondary" disabled>Backup Database</button>
                                </div>
                                
                                <div class="action-item">
                                    <div class="action-info">
                                        <h4>Pengaturan Discord</h4>
                                        <p>Konfigurasi integrasi dengan Discord bot (fitur akan segera tersedia)</p>
                                    </div>
                                    <button class="btn btn-secondary" disabled>Konfigurasi Discord</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        function showAdminTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-admin-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        function confirmRoleChange(employeeName, form) {
            const newRoleSelect = form.querySelector('select[name="new_role"]');
            const newRole = newRoleSelect.value;
            
            // Dapatkan nilai role saat ini dari atribut data-role atau option yang selected
            const currentSelectedOption = newRoleSelect.querySelector('option[value="' + newRoleSelect.value + '"][selected]');
            const currentRole = currentSelectedOption ? currentSelectedOption.value : null;
            
            const roleNames = {
                'ceo': 'CEO',
                'direktur': 'Direktur',
                'wakil_direktur': 'Wakil Direktur', 
                'manager': 'Manager',
                'chef': 'Chef',
                'waiters': 'Waiters',
                'karyawan': 'Karyawan',
                'magang': 'Magang'
            };

            if (!newRole) {
                showNotification('Pilih jabatan baru terlebih dahulu!', 'error'); // Use custom notification
                return false;
            }

            if (currentRole && currentRole === newRole) { // Bandingkan dengan currentRole yang didapat dari DOM
                showNotification('Jabatan yang dipilih sama dengan jabatan saat ini!', 'error');
                return false;
            }

            if (confirm(`Yakin ingin mengubah jabatan ${employeeName} menjadi ${roleNames[newRole]}?`)) {
                form.submit(); // Programmatically submit the form
                return true;
            }
            return false;
        }

        function confirmDeactivation(form, employeeName) {
            if (confirm(`Yakin ingin menonaktifkan ${employeeName}?\n\nAnggota yang dinonaktifkan tidak dapat login lagi.`)) {
                form.submit(); // Programmatically submit the form
                return true;
            }
            return false;
        }
        
        // Custom submit handler for add_employee_form to prevent accidental submission
        document.addEventListener('DOMContentLoaded', function() {
            const addEmployeeForm = document.getElementById('add_employee_form');
            if (addEmployeeForm) {
                addEmployeeForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Mencegah pengiriman formulir otomatis
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn.disabled) return;

                    submitBtn.disabled = true;
                    const originalText = submitBtn.textContent;
                    submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';

                    setTimeout(() => {
                        addEmployeeForm.submit(); // Kirim formulir secara programatis
                    }, 100);
                });
            }

            // General form submit handler for other forms (e.g., system actions and discord update)
            const otherForms = document.querySelectorAll('form:not(#add_employee_form):not(.role-change-form):not(.deactivate-form)');
            
            otherForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.textContent;
                        submitBtn.innerHTML = '<span class="spinner"></span> Memproses...';
                        
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }, 5000); // Fallback re-enable after 5 seconds
                    }
                });
            });
        });
    </script>
</body>
</html>