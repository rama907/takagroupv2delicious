<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser(); // Dapatkan user yang sedang login untuk approved_by

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Inisialisasi variabel feedback
$success = null;
$error = null;

// --- Handle action untuk manual On Duty (NEW LOGIC) ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'manual_on_duty')) {
    $employee_id_to_on_duty = (int)($_POST['employee_id'] ?? 0);

    if ($employee_id_to_on_duty <= 0) {
        $error = "ID anggota tidak valid!";
    } elseif ($employee_id_to_on_duty == $user['id']) {
        $error = "Anda tidak bisa meng-on duty diri sendiri secara manual dari sini. Silakan gunakan tombol On Duty di Dashboard.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt_get_employee = $conn->prepare("SELECT name, is_on_duty FROM employees WHERE id = ? AND status = 'active'");
            if (!$stmt_get_employee) {
                throw new Exception("Gagal menyiapkan query ambil data anggota: " . $conn->error);
            }
            $stmt_get_employee->bind_param("i", $employee_id_to_on_duty);
            $stmt_get_employee->execute();
            $employee_data = $stmt_get_employee->get_result()->fetch_assoc();
            $stmt_get_employee->close();

            if (!$employee_data || $employee_data['is_on_duty']) {
                throw new Exception("Anggota tidak ditemukan atau sudah On Duty.");
            }
            
            // 1. Update status employee
            $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
            if (!$stmt_update_employee) {
                throw new Exception("Gagal menyiapkan query update status anggota: " . $conn->error);
            }
            $stmt_update_employee->bind_param("i", $employee_id_to_on_duty);
            if (!$stmt_update_employee->execute()) {
                throw new Exception("Gagal mengupdate status anggota: " . $stmt_update_employee->error);
            }
            $stmt_update_employee->close();

            // 2. Insert new active duty log (simulating manual clock-in)
            // Log manual di sini dicatat sebagai 'active' karena merupakan awal shift
            $stmt_insert_log = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start, is_manual, approved_by, status) VALUES (?, NOW(), 1, ?, 'active')");
            if (!$stmt_insert_log) {
                throw new Exception("Gagal membuat log duty baru: " . $conn->error);
            }
            $stmt_insert_log->bind_param("ii", $employee_id_to_on_duty, $user['id']);
            if (!$stmt_insert_log->execute()) {
                throw new Exception("Gagal membuat log duty baru: " . $stmt_insert_log->error);
            }
            $stmt_insert_log->close();

            $conn->commit();
            $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil diatur **On Duty** secara manual!";

            // Kirim notifikasi Discord
            sendDiscordNotification([
                'employee_name' => htmlspecialchars($employee_data['name']),
                'action_type' => 'Manual On Duty',
                'admin_name' => htmlspecialchars($user['name']),
                'event_type' => 'clock_in', 
            ], 'clock_event');
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan saat meng-on duty anggota: " . $e->getMessage();
        }
    }
}
// --- AKHIR LOGIKA MANUAL ON DUTY ---


// --- Handle action untuk manual Off Duty (EXISTING LOGIC) ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'manual_off_duty')) {
    $employee_id_to_off_duty = (int)($_POST['employee_id'] ?? 0);

    if ($employee_id_to_off_duty <= 0) {
        $error = "ID anggota tidak valid!";
    } elseif ($employee_id_to_off_duty == $user['id']) {
        $error = "Anda tidak bisa meng-off duty diri sendiri secara manual dari sini. Silakan gunakan tombol Off Duty di Dashboard.";
    } else {
        // Mulai transaksi untuk memastikan konsistensi data
        $conn->begin_transaction();
        try {
            // Ambil data karyawan yang akan di-off duty
            $stmt_get_employee = $conn->prepare("SELECT name, is_on_duty, current_duty_start FROM employees WHERE id = ? AND status = 'active'");
            if (!$stmt_get_employee) {
                throw new Exception("Gagal menyiapkan query ambil data anggota: " . $conn->error);
            }
            $stmt_get_employee->bind_param("i", $employee_id_to_off_duty);
            $stmt_get_employee->execute();
            $employee_data = $stmt_get_employee->get_result()->fetch_assoc();
            $stmt_get_employee->close();

            if (!$employee_data || !$employee_data['is_on_duty']) {
                throw new Exception("Anggota tidak ditemukan atau tidak sedang On Duty.");
            }

            // 1. Update duty_logs (cari record yang aktif dan akhiri)
            $stmt_get_log = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
            if (!$stmt_get_log) {
                throw new Exception("Gagal menyiapkan query ambil log duty: " . $conn->error);
            }
            $stmt_get_log->bind_param("i", $employee_id_to_off_duty);
            $stmt_get_log->execute();
            $active_log = $stmt_get_log->get_result()->fetch_assoc();
            $stmt_get_log->close();

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
                    throw new Exception("Gagal mengupdate log duty: " . $stmt_update_log->error);
                }
                $stmt_update_log->close();
            } else {
                error_log("Warning: Employee " . $employee_data['name'] . " is_on_duty=TRUE but no active duty_log found.");
            }

            // 2. Update status employee
            $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
            if (!$stmt_update_employee) {
                throw new Exception("Gagal menyiapkan query update status anggota: " . $conn->error);
            }
            $stmt_update_employee->bind_param("i", $employee_id_to_off_duty);
            if (!$stmt_update_employee->execute()) {
                throw new Exception("Gagal mengupdate status anggota: " . $stmt_update_employee->error);
            }
            $stmt_update_employee->close();

            $conn->commit(); // Commit transaksi
            $success = "Anggota " . htmlspecialchars($employee_data['name']) . " berhasil diatur **Off Duty** secara manual!";

            // Kirim notifikasi Discord
            sendDiscordNotification([
                'employee_name' => htmlspecialchars($employee_data['name']),
                'request_type' => 'Manual Off Duty',
                'status' => 'completed',
                'approved_by_name' => htmlspecialchars($user['name']),
            ], 'request_status_update'); 
            
        } catch (Exception $e) {
            $conn->rollback(); // Rollback transaksi jika ada error
            $error = "Terjadi kesalahan saat meng-off duty anggota: " . $e->getMessage();
        }
    }
}
// --- AKHIR LOGIKA MANUAL OFF DUTY ---


// Get all employees
$stmt = $conn->query("
    SELECT e.*, 
           CASE WHEN e.is_on_duty THEN 'On Duty' ELSE 'Off Duty' END as duty_status
    FROM employees e 
    WHERE e.status = 'active'
    ORDER BY 
        CASE e.role 
            WHEN 'ceo' THEN 1
            WHEN 'direktur' THEN 2
            WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4
            WHEN 'chef' THEN 5
            WHEN 'waiters' THEN 6
            WHEN 'karyawan' THEN 7
            WHEN 'magang' THEN 8
        END,
        e.name
");
$employees = $stmt->fetch_all(MYSQLI_ASSOC);

// Inisialisasi variabel untuk alert long duty (untuk daftar anggota)
$long_duty_members_info = [];
foreach ($employees as $key => $employee) {
    if ($employee['is_on_duty'] && $employee['current_duty_start']) {
        $start_time = new DateTime($employee['current_duty_start']);
        $now_time = new DateTime();
        $duty_duration_seconds = $now_time->getTimestamp() - $start_time->getTimestamp();
        
        if ($duty_duration_seconds > (5 * 3600)) { // Lebih dari 5 jam (5 * 60 * 60 detik)
            $long_duty_members_info[] = [
                'name' => htmlspecialchars($employee['name']),
                'duration' => formatDuration($duty_duration_seconds / 60) // Konversi detik ke menit untuk formatDuration
            ];
            // Tambahkan flag ke data karyawan untuk indikasi di UI
            $employees[$key]['is_long_duty'] = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Anggota - Delicious</title>
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
                    <span class="page-icon">👥</span>
                    Daftar Anggota
                </h1>
                <p>Daftar semua anggota Delicious</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message"><?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?= $error ?></div>
            <?php endif; ?>

            <?php if (!empty($long_duty_members_info)): ?>
                <div class="warning-message" style="margin-bottom: var(--spacing-xl);">
                    <strong>⚠️ Perhatian:</strong> Ada anggota yang sudah On Duty lebih dari 5 jam:
                    <ul>
                        <?php foreach ($long_duty_members_info as $member): ?>
                            <li><?= $member['name'] ?> (<?= $member['duration'] ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                    Pertimbangkan untuk menghubungi mereka atau melakukan "Off Duty Manual".
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Anggota Aktif</h3>
                    <span class="member-count"><?= count($employees) ?> Anggota</span>
                </div>
                <div class="card-content">
                    <div class="employees-grid">
                        <?php foreach ($employees as $employee): ?>
                        <div class="employee-card">
                            <div class="employee-avatar">
                                <span class="avatar-icon">👤</span>
                            </div>
                            <div class="employee-info">
                                <h4 class="employee-name"><?= htmlspecialchars($employee['name']) ?></h4>
                                <p class="employee-role"><?= getRoleDisplayName($employee['role']) ?></p>
                                <div class="employee-status">
                                    <span class="status-indicator <?= $employee['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                    <span class="status-text"><?= $employee['duty_status'] ?></span>
                                </div>
                                <?php if ($employee['is_on_duty'] && $employee['current_duty_start']): ?>
                                    <div class="duty-timer" data-start-time="<?= $employee['current_duty_start'] ?>">
                                        00:00:00
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($employee['is_long_duty']) && $employee['is_long_duty']): ?>
                                    <div class="info-message" style="margin-top: var(--spacing-sm); font-size: 0.85rem; padding: 0.5rem; border-left: 3px solid var(--warning-color);">
                                        Sudah On Duty > 5 jam
                                    </div>
                                <?php endif; ?>

                                <?php if ($employee['id'] != $user['id']): ?>
                                <div class="manual-duty-actions" style="margin-top: var(--spacing-md);">
                                    <?php if (!$employee['is_on_duty']): ?>
                                    <form method="POST" onsubmit="return confirm('Yakin ingin memulai sesi On Duty manual untuk <?= htmlspecialchars($employee['name']) ?> sekarang?');">
                                        <input type="hidden" name="action" value="manual_on_duty">
                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <span class="btn-icon">✅</span> On Duty Manual
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" onsubmit="return confirm('Yakin ingin mengakhiri sesi On Duty untuk <?= htmlspecialchars($employee['name']) ?> sekarang? Tindakan ini akan mencatat waktu Off Duty saat ini.');">
                                        <input type="hidden" name="action" value="manual_off_duty">
                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <span class="btn-icon">❌</span> Off Duty Manual
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        // Update duty timers for all employees
        function updateAllDutyTimers() {
            const timers = document.querySelectorAll('.duty-timer');
            timers.forEach(timer => {
                const startTime = timer.dataset.startTime;
                if (startTime) {
                    const start = new Date(startTime);
                    const now = new Date();
                    const diff = now - start;
                    
                    const hours = Math.floor(diff / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                    
                    timer.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                }
            });
        }
        
        setInterval(updateAllDutyTimers, 1000);
    </script>
</body>
</html>