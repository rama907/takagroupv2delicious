<?php
require_once 'config.php';

if (!isLoggedIn()) {
    // Mengarahkan ke root (/) untuk halaman index/login
    header('Location: /');
    exit;
}
$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Ambil status surat peringatan terbaru dari database
$warning_status = null;
$stmt_sp = $conn->prepare("
    SELECT sp_type
    FROM warning_letters
    WHERE employee_id = ?
    ORDER BY issued_at DESC
    LIMIT 1
");
if ($stmt_sp) {
    $stmt_sp->bind_param("i", $user['id']);
    $stmt_sp->execute();
    $result_sp = $stmt_sp->get_result();

    if ($result_sp->num_rows > 0) {
        $warning_status = $result_sp->fetch_assoc()['sp_type'];
    }
    $stmt_sp->close();
}

// Handle duty actions
if (isset($_POST['action']) && $_POST['action'] === 'on_duty') {
    if (!$user['is_on_duty']) {
        
        // 1. Update status employee
        $stmt = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        // 2. Insert log (is_manual=0, status='active' for automatic web clock-in)
        $stmt = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start, is_manual, status) VALUES (?, NOW(), 0, 'active')");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        sendDiscordNotification(['employee_name' => $user['name'], 'event_type' => 'clock_in'], 'clock_event');
        header('Location: dashboard');
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'off_duty') {
    if ($user['is_on_duty']) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $log = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($log) {
                // FIX: Gunakan TIMESTAMPDIFF(MINUTE, ...) untuk menghitung durasi yang akurat dan set status ke 'completed'
                $stmt_update_log = $conn->prepare("
                    UPDATE duty_logs 
                    SET duty_end = NOW(), 
                        duration_minutes = TIMESTAMPDIFF(MINUTE, duty_start, NOW()), 
                        status = 'completed' 
                    WHERE id = ?
                ");
                if (!$stmt_update_log) {
                    throw new Exception("Gagal menyiapkan query update log duty: " . $conn->error);
                }
                $stmt_update_log->bind_param("i", $log['id']);
                $stmt_update_log->execute();
                $stmt_update_log->close();
            }
            
            // Update status employee
            $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
            if (!$stmt_update_employee) {
                 throw new Exception("Gagal menyiapkan query update status karyawan: " . $conn->error);
            }
            $stmt_update_employee->bind_param("i", $user['id']);
            $stmt_update_employee->execute();
            $stmt_update_employee->close();
            
            $conn->commit();

            // Ambil durasi yang baru dihitung untuk notifikasi Discord
            $stmt_log_duration = $conn->prepare("SELECT duty_start, duration_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
            $stmt_log_duration->bind_param("i", $user['id']);
            $stmt_log_duration->execute();
            $last_log = $stmt_log_duration->get_result()->fetch_assoc();
            $stmt_log_duration->close();
            
            $duration_text = formatDuration($last_log['duration_minutes'] ?? 0);

            sendDiscordNotification(['employee_name' => $user['name'], 'event_type' => 'clock_out', 'duration' => $duration_text], 'clock_event');
            header('Location: dashboard');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Off Duty Error: " . $e->getMessage());
            // Redirect dengan pesan error
            header('Location: dashboard?msg=' . urlencode('Terjadi kesalahan saat Clock Out: ' . $e->getMessage()) . '&type=error');
            exit;
        }
    }
}

// Get user stats
$stmt = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$total_minutes = $stmt->get_result()->fetch_assoc()['total_minutes'] ?? 0;
$stmt->close();

// === MODIFIKASI: QUERY UNTUK TOTAL PENJUALAN (Update Struktur Baru) ===
// Menjumlahkan kolom spesifik dari tabel sales_data TERMASUK MENU BARU
$total_sales_packages_dashboard = 0;
$stmt_sales_packages = $conn->prepare("
    SELECT
        COALESCE(SUM(paket_western), 0) + 
        COALESCE(SUM(paket_nusantara), 0) + 
        COALESCE(SUM(paket_kids), 0) + 
        COALESCE(SUM(happy_bites), 0) + 
        COALESCE(SUM(paket_royale), 0) +
        COALESCE(SUM(paket_sweet_coffee), 0) + 
        COALESCE(SUM(paket_matchamisu), 0) as total_sales
    FROM sales_data
    WHERE employee_id = ?
");

if ($stmt_sales_packages) {
    $stmt_sales_packages->bind_param("i", $user['id']);
    $stmt_sales_packages->execute();
    $result_sales = $stmt_sales_packages->get_result()->fetch_assoc();
    if ($result_sales) {
        $total_sales_packages_dashboard = (int)$result_sales['total_sales'];
    }
    $stmt_sales_packages->close();
}
$total_paket_terjual_dashboard = $total_sales_packages_dashboard;


// === MODIFIKASI: QUERY UNTUK TOTAL MASAK (Update Struktur Baru) ===
// Mengambil data dari tabel cooking_data TERMASUK MENU BARU
$total_prep_packages_dashboard = 0;
$stmt_prep_packages = $conn->prepare("
    SELECT
        COALESCE(SUM(paket_western), 0) + 
        COALESCE(SUM(paket_nusantara), 0) + 
        COALESCE(SUM(paket_kids), 0) + 
        COALESCE(SUM(happy_bites), 0) + 
        COALESCE(SUM(paket_royale), 0) +
        COALESCE(SUM(paket_sweet_coffee), 0) + 
        COALESCE(SUM(paket_matchamisu), 0) as total_cooking
    FROM cooking_data
    WHERE employee_id = ?
");

if ($stmt_prep_packages) {
    $stmt_prep_packages->bind_param("i", $user['id']);
    $stmt_prep_packages->execute();
    $result_prep = $stmt_prep_packages->get_result()->fetch_assoc();
    if ($result_prep) {
        $total_prep_packages_dashboard = (int)$result_prep['total_cooking'];
    }
    $stmt_prep_packages->close();
}
$total_paket_masak_dashboard = $total_prep_packages_dashboard;


// Get recent activities
$stmt = $conn->prepare("SELECT * FROM duty_logs WHERE employee_id = ? ORDER BY duty_start DESC LIMIT 5");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


// Get current duty duration if on duty for alert
$current_duty_duration_seconds = 0;
$long_duty_alert_dashboard = false;
if ($user['is_on_duty'] && $user['current_duty_start']) {
    $start = new DateTime($user['current_duty_start']);
    $now = new DateTime();
    $current_duty_duration_seconds = $now->getTimestamp() - $start->getTimestamp();
    
    if ($current_duty_duration_seconds > (5 * 3600)) {
        $long_duty_alert_dashboard = true;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* CSS untuk logo di dalam stat-card */
        .stat-icon img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
            margin: auto;
        }
        
        /* CSS untuk logo Royale Grill & Bar di header dashboard */
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: none !important; /* Hapus latar belakang kuning/gradient */
            border-radius: 0 !important; /* Hapus efek lingkaran */
            border: none !important; /* Hapus border */
            box-shadow: none !important; /* Hapus bayangan */
            padding: 0; /* Hapus padding */
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative; 
        }
        /* Style untuk gambar logo di dalam profile-avatar */
        .profile-avatar img.logo-img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            width: 100%;
            height: 100%;
            padding: 0; /* Hapus padding internal */
            transform: scale(1); 
        }
        /* Sembunyikan ikon placeholder lama di dalam profile-avatar */
        .profile-avatar span {
            display: none;
        }
        /* Ganti Judul Lama */
        .profile-info h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: var(--spacing-sm);
            letter-spacing: -0.025em;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <?php if (isset($_GET['msg']) && $_GET['type'] == 'error'): ?>
                <div class="error-message">❌ <?= htmlspecialchars($_GET['msg']) ?></div>
            <?php endif; ?>
            <?php if ($long_duty_alert_dashboard): ?>
                <div class="warning-message" style="margin-bottom: var(--spacing-xl);">
                    <strong>⚠️ Perhatian:</strong> Anda sudah On Duty lebih dari 5 jam. Pastikan Anda beristirahat yang cukup!
                </div>
            <?php endif; ?>

            <div class="dashboard-header">
                <div class="user-profile">
                    <div class="profile-avatar">
                        <img src="LOGO_WOT.png" alt="Delicious Logo" class="logo-img">
                    </div>
                    <div class="profile-info">
                        <h1>Sistem Manajemen Delicious</h1> 
                        <div class="user-details">
                            <span class="user-icon">👤</span>
                            <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-card">
                <?php if ($warning_status): ?>
                <div class="sp-card-badge">
                    <span class="sp-badge sp-<?= strtolower($warning_status) ?>">
                        <?= htmlspecialchars($warning_status) ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="user-card-content">
                    <div class="user-info">
                        <h2><?= htmlspecialchars($user['name']) ?></h2>
                        <p class="user-role"><?= getRoleDisplayName($user['role']) ?></p>
                        <div class="duty-status">
                            <span class="status-indicator <?= $user['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                            <span class="status-text"><?= $user['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                            <?php if ($user['is_on_duty']): ?>
                                <div class="duty-clock" data-start-time="<?= $user['current_duty_start'] ?>">
                                    00:00:00
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="on_duty">
                    <button type="submit" class="btn btn-success" <?= $user['is_on_duty'] ? 'disabled' : '' ?>>
                        <span class="btn-icon">▶️</span>
                        On Duty
                    </button>
                </form>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="off_duty">
                    <button type="submit" class="btn btn-warning" <?= !$user['is_on_duty'] ? 'disabled' : '' ?>>
                        <span class="btn-icon">⏸️</span>
                        Off Duty
                    </button>
                </form>
                <a href="sales" class="btn btn-primary">
                    <span class="btn-icon">💰</span>
                    Input Penjualan
                </a>
                <a href="data-masak" class="btn btn-primary">
                    <span class="btn-icon">🔪</span>
                    Input Data Masak
                </a>
                <a href="leave-request" class="btn btn-info">
                    <span class="btn-icon">📝</span>
                    Cuti
                </a>
                <a href="resignation-request" class="btn btn-danger">
                    <span class="btn-icon">📄</span>
                    Resign
                </a>
                <a href="manual-duty" class="btn btn-primary">
                    <span class="btn-icon">⏱️</span>
                    Input Jam Manual
                </a>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-content">
                        <h3>Total Jam Kerja</h3>
                        <p class="stat-value"><?= formatDuration($total_minutes) ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        💰 </div>
                    <div class="stat-content">
                        <h3>Total Penjualan</h3>
                        <p class="stat-value"><?= $total_paket_terjual_dashboard ?> Paket</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        🔪 </div>
                    <div class="stat-content">
                        <h3>Total Masak</h3>
                        <p class="stat-value"><?= $total_paket_masak_dashboard ?> Paket</p>
                    </div>
                </div>
            </div>

            <div class="recent-activities">
                <h3>
                    <span class="section-icon">📊</span>
                    Aktivitas Terakhir
                </h3>
                <div class="activities-list">
                    <?php if (empty($recent_activities)): ?>
                        <div class="no-data">Belum ada aktivitas</div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <?php 
                        // Tentukan TIPE tampilan
                        $display_type = 'Otomatis';
                        $status_class = 'info';
                        if ($activity['is_manual'] == 1) {
                            $display_type = 'Manual (Web)';
                            $status_class = 'warning';
                        } elseif ($activity['is_manual'] == 2) {
                            $display_type = 'Discord/Bot';
                            $status_class = 'primary';
                        }

                        // Tentukan durasi tampilan
                        $is_active = $activity['status'] === 'active';
                        $display_end_time = $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : '-';
                        $display_duration = $is_active ? 'Berlangsung' : formatDuration($activity['duration_minutes']);
                        $display_status = ucfirst($activity['status']);
                        ?>
                        <div class="activity-item">
                            <div class="activity-date">
                                <span class="date-icon">📅</span>
                                <?= date('d/m/Y', strtotime($activity['duty_start'])) ?>
                            </div>
                            <div class="activity-time">
                                <span class="time-icon">⏰</span>
                                <?= date('H:i', strtotime($activity['duty_start'])) ?> - 
                                <?= $display_end_time ?>
                            </div>
                            <div class="activity-duration">
                                <strong>Tipe:</strong> 
                                <span class="status-badge status-<?= $status_class ?>">
                                    <?= $display_type ?>
                                </span>
                                | <strong>Status:</strong>
                                <span class="status-badge status-<?= $activity['status'] ?>">
                                    <?= $display_status ?>
                                </span>
                                | <strong>Durasi:</strong> 
                                <?= $display_duration ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>