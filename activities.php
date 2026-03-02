<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi formatDuration ada di config.php atau di-include di tempat lain
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m"; // Tangani durasi negatif dengan baik
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($role) {
        $roles = [
            'ceo' => 'CEO',
            'direktur' => 'Direktur',
            'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager',
            'barista' => 'Barista',
            'waiters' => 'Waiters',
            'guard' => 'Guard',
            'karyawan' => 'Karyawan',
            'magang' => 'Magang',
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

$success_message = null;
$error_message = null;

// --- Handle Delete Duty Log (Existing Logic) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_duty_log') {
    $duty_log_id = (int)($_POST['duty_log_id'] ?? 0);
    $employee_id_of_log = $user['id']; // ID karyawan yang sedang login

    if ($duty_log_id <= 0) {
        $error_message = "ID log duty tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail log sebelum dihapus untuk notifikasi dan cek status
            $stmt_get_log = $conn->prepare("
                SELECT dl.id, dl.duty_start, dl.duty_end, dl.duration_minutes, dl.status, dl.is_manual
                FROM duty_logs dl
                WHERE dl.id = ? AND dl.employee_id = ?
            ");
            if (!$stmt_get_log) {
                throw new Exception("Gagal menyiapkan query ambil detail log: " . $conn->error);
            }
            $stmt_get_log->bind_param("ii", $duty_log_id, $employee_id_of_log);
            $stmt_get_log->execute();
            $log_details = $stmt_get_log->get_result()->fetch_assoc();
            $stmt_get_log->close();

            if (!$log_details) {
                throw new Exception("Log duty tidak ditemukan atau bukan milik Anda.");
            }

            // Jika log yang dihapus adalah log 'active' (on duty saat ini)
            if ($log_details['status'] === 'active') {
                // Update status on_duty karyawan menjadi OFF
                $stmt_update_employee_status = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
                if (!$stmt_update_employee_status) {
                    throw new Exception("Gagal menyiapkan query update status karyawan: " . $conn->error);
                }
                $stmt_update_employee_status->bind_param("i", $employee_id_of_log);
                if (!$stmt_update_employee_status->execute()) {
                    throw new Exception("Gagal mengupdate status karyawan: " . $stmt_update_employee_status->error);
                }
                $stmt_update_employee_status->close();
            }

            // Hapus log duty
            $stmt_delete = $conn->prepare("DELETE FROM duty_logs WHERE id = ? AND employee_id = ?");
            if (!$stmt_delete) {
                die("Fatal Error: Gagal menyiapkan query hapus log duty. MySQL Error: " . $conn->error); 
            }
            $stmt_delete->bind_param("ii", $duty_log_id, $employee_id_of_log);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Log jam kerja pada tanggal " . date('d/m/Y H:i', strtotime($log_details['duty_start'])) . " berhasil dihapus.";

                sendDiscordNotification([
                    'employee_name' => $user['name'], 
                    'admin_name' => $user['name'], 
                    'duty_start' => $log_details['duty_start'],
                    'duty_end' => $log_details['duty_end'],
                    'duration_minutes' => $log_details['duration_minutes']
                ], 'duty_log_deleted');

            } else {
                throw new Exception("Gagal menghapus log duty. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: activities.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
        exit;
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}


// Get all activities for current user
$stmt = $conn->prepare("
    SELECT * FROM duty_logs
    WHERE employee_id = ?
    ORDER BY duty_start DESC
    LIMIT 50
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close(); 

// Ambil ringkasan total jam kerja KESELURUHAN pengguna
$stmt = $conn->prepare("SELECT SUM(duration_minutes) as total_minutes FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$total_duty_minutes = $stmt->get_result()->fetch_assoc()['total_minutes'] ?? 0;
$stmt->close();

// === MODIFIKASI: Ambil ringkasan data penjualan dan masak KESELURUHAN untuk pengguna ===
$sales_prep_summary = [
    'paket_western_sales' => 0,
    'paket_nusantara_sales' => 0,
    'paket_kids_meal_sales' => 0,
    'happy_bites_sales' => 0,
    'paket_royale_sales' => 0,
    'paket_sweet_coffee_sales' => 0,
    'paket_matchamisu_sales' => 0,
    'total_penjualan' => 0,
    
    'paket_western_prep' => 0,
    'paket_nusantara_prep' => 0,
    'paket_kids_meal_prep' => 0,
    'happy_bites_prep' => 0,
    'paket_royale_prep' => 0,
    'paket_sweet_coffee_prep' => 0,
    'paket_matchamisu_prep' => 0,
    'total_masak' => 0,
];

// 1. Ambil Data Penjualan (Tabel sales_data)
$stmt = $conn->prepare("
    SELECT
        SUM(paket_western) as paket_western_sales,
        SUM(paket_nusantara) as paket_nusantara_sales,
        SUM(paket_kids) as paket_kids_meal_sales,
        SUM(happy_bites) as happy_bites_sales,
        SUM(paket_royale) as paket_royale_sales,
        SUM(paket_sweet_coffee) as paket_sweet_coffee_sales,
        SUM(paket_matchamisu) as paket_matchamisu_sales
    FROM sales_data
    WHERE employee_id = ?
");

if ($stmt) {
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result_sales = $stmt->get_result()->fetch_assoc();
    if ($result_sales) {
        $sales_prep_summary['paket_western_sales'] = $result_sales['paket_western_sales'] ?? 0;
        $sales_prep_summary['paket_nusantara_sales'] = $result_sales['paket_nusantara_sales'] ?? 0;
        $sales_prep_summary['paket_kids_meal_sales'] = $result_sales['paket_kids_meal_sales'] ?? 0;
        $sales_prep_summary['happy_bites_sales'] = $result_sales['happy_bites_sales'] ?? 0;
        $sales_prep_summary['paket_royale_sales'] = $result_sales['paket_royale_sales'] ?? 0;
        $sales_prep_summary['paket_sweet_coffee_sales'] = $result_sales['paket_sweet_coffee_sales'] ?? 0;
        $sales_prep_summary['paket_matchamisu_sales'] = $result_sales['paket_matchamisu_sales'] ?? 0;
    }
    $stmt->close();
}

// 2. Ambil Data Masak (Tabel cooking_data)
$stmt = $conn->prepare("
    SELECT
        SUM(paket_western) as paket_western_prep,
        SUM(paket_nusantara) as paket_nusantara_prep,
        SUM(paket_kids) as paket_kids_meal_prep,
        SUM(happy_bites) as happy_bites_prep,
        SUM(paket_royale) as paket_royale_prep,
        SUM(paket_sweet_coffee) as paket_sweet_coffee_prep,
        SUM(paket_matchamisu) as paket_matchamisu_prep
    FROM cooking_data
    WHERE employee_id = ?
");

if ($stmt) {
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result_prep = $stmt->get_result()->fetch_assoc();
    if ($result_prep) {
        $sales_prep_summary['paket_western_prep'] = $result_prep['paket_western_prep'] ?? 0;
        $sales_prep_summary['paket_nusantara_prep'] = $result_prep['paket_nusantara_prep'] ?? 0;
        $sales_prep_summary['paket_kids_meal_prep'] = $result_prep['paket_kids_meal_prep'] ?? 0;
        $sales_prep_summary['happy_bites_prep'] = $result_prep['happy_bites_prep'] ?? 0;
        $sales_prep_summary['paket_royale_prep'] = $result_prep['paket_royale_prep'] ?? 0;
        $sales_prep_summary['paket_sweet_coffee_prep'] = $result_prep['paket_sweet_coffee_prep'] ?? 0;
        $sales_prep_summary['paket_matchamisu_prep'] = $result_prep['paket_matchamisu_prep'] ?? 0;
    }
    $stmt->close();
}

// Hitung Total
$sales_prep_summary['total_penjualan'] = 
    $sales_prep_summary['paket_western_sales'] + 
    $sales_prep_summary['paket_nusantara_sales'] + 
    $sales_prep_summary['paket_kids_meal_sales'] +
    $sales_prep_summary['happy_bites_sales'] +
    $sales_prep_summary['paket_royale_sales'] +
    $sales_prep_summary['paket_sweet_coffee_sales'] +
    $sales_prep_summary['paket_matchamisu_sales'];

$sales_prep_summary['total_masak'] = 
    $sales_prep_summary['paket_western_prep'] + 
    $sales_prep_summary['paket_nusantara_prep'] + 
    $sales_prep_summary['paket_kids_meal_prep'] +
    $sales_prep_summary['happy_bites_prep'] +
    $sales_prep_summary['paket_royale_prep'] +
    $sales_prep_summary['paket_sweet_coffee_prep'] +
    $sales_prep_summary['paket_matchamisu_prep'];

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Saya - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .stat-breakdown span {
            display: inline-block;
            margin-right: 10px;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📊</span>
                    Aktivitas Saya
                </h1>
                <p>Riwayat aktivitas dan ringkasan performa Anda</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Total Jam Kerja Keseluruhan</h4>
                        <p class="summary-value">
                            <?= formatDuration($total_duty_minutes) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">💰</div>
                    <div class="summary-content">
                        <h4>Total Penjualan Paket</h4>
                        <p class="summary-value">
                            <?= $sales_prep_summary['total_penjualan'] ?> Paket
                        </p>
                        <p class="stat-breakdown" style="font-size: 0.85em; margin-top: 0.5rem; text-align: left; line-height: 1.5;">
                            <span>Western: <strong><?= $sales_prep_summary['paket_western_sales'] ?></strong></span>
                            <span>Nusantara: <strong><?= $sales_prep_summary['paket_nusantara_sales'] ?></strong></span>
                            <span>Kids Meal: <strong><?= $sales_prep_summary['paket_kids_meal_sales'] ?></strong></span>
                            <span>Happy Bites: <strong><?= $sales_prep_summary['happy_bites_sales'] ?></strong></span>
                            <span>Royale: <strong><?= $sales_prep_summary['paket_royale_sales'] ?></strong></span>
                            <span>Sweet Coffee: <strong><?= $sales_prep_summary['paket_sweet_coffee_sales'] ?></strong></span>
                            <span>Matcha Misu: <strong><?= $sales_prep_summary['paket_matchamisu_sales'] ?></strong></span>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--warning-color);">🔪</div>
                    <div class="summary-content">
                        <h4>Total Masak Paket</h4>
                        <p class="summary-value">
                            <?= $sales_prep_summary['total_masak'] ?> Paket
                        </p>
                        <p class="stat-breakdown" style="font-size: 0.85em; margin-top: 0.5rem; text-align: left; line-height: 1.5;">
                            <span>Western: <strong><?= $sales_prep_summary['paket_western_prep'] ?></strong></span>
                            <span>Nusantara: <strong><?= $sales_prep_summary['paket_nusantara_prep'] ?></strong></span>
                            <span>Kids Meal: <strong><?= $sales_prep_summary['paket_kids_meal_prep'] ?></strong></span>
                            <span>Happy Bites: <strong><?= $sales_prep_summary['happy_bites_prep'] ?></strong></span>
                            <span>Royale: <strong><?= $sales_prep_summary['paket_royale_prep'] ?></strong></span>
                            <span>Sweet Coffee: <strong><?= $sales_prep_summary['paket_sweet_coffee_prep'] ?></strong></span>
                            <span>Matcha Misu: <strong><?= $sales_prep_summary['paket_matchamisu_prep'] ?></strong></span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="content-grid">
                <div class="card full-width"> <div class="card-header">
                        <h3>Riwayat Aktivitas Anda</h3>
                    </div>
                    <div class="card-content">
                        <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                            <strong>💡 Info:</strong> Baris dengan latar belakang kuning mengindikasikan jam kerja per absensi yang melebihi **7 jam**.
                        </div>
                        <?php if (empty($activities)): ?>
                            <div class="no-data">Belum ada aktivitas. Silakan lakukan 'On Duty' untuk mencatat aktivitas pertama Anda.</div>
                        <?php else: ?>
                            <div class="responsive-table-container">
                                <table class="activities-table-improved">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Mulai</th>
                                            <th>Selesai</th>
                                            <th>Durasi</th>
                                            <th>Tipe</th>
                                            <th>Status</th>
                                            <th>Aksi</th> </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activities as $activity): ?>
                                        <?php
                                        // Cek jika durasi melebihi 7 jam (420 menit) - hanya berlaku untuk log completed
                                        $is_long_duty = ($activity['status'] === 'completed' && $activity['duration_minutes'] > 420);
                                        $is_active = $activity['status'] === 'active';
                                        
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
                                        $display_duration = $is_active ? 'Berlangsung' : formatDuration($activity['duration_minutes']);
                                        $display_end_time = $activity['duty_end'] ? date('H:i', strtotime($activity['duty_end'])) : '-';
                                        $display_status = ucfirst($activity['status']);
                                        ?>
                                        <tr class="<?= $is_long_duty ? 'long-duty-row' : '' ?>">
                                            <td data-label="Tanggal">
                                                <?= date('d/m/Y', strtotime($activity['duty_start'])) ?>
                                            </td>
                                            <td data-label="Mulai">
                                                <?= date('H:i', strtotime($activity['duty_start'])) ?>
                                            </td>
                                            <td data-label="Selesai">
                                                <?= $display_end_time ?>
                                            </td>
                                            <td data-label="Durasi">
                                                <strong><?= $display_duration ?></strong>
                                                <?php if ($is_long_duty): ?>
                                                    <span class="long-duty-alert">
                                                        <span class="btn-icon">⚠️</span> >7 Jam
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Tipe">
                                                <span class="status-badge status-<?= $status_class ?>">
                                                    <?= $display_type ?>
                                                </span>
                                            </td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?= $activity['status'] ?>">
                                                    <?= $display_status ?>
                                                </span>
                                            </td>
                                            <td data-label="Aksi">
                                                <?php if ($activity['status'] !== 'pending_approval'): ?>
                                                <form method="POST" onsubmit="return confirm('Yakin ingin menghapus log jam kerja ini? Aksi ini TIDAK DAPAT DIBATALKAN. Catatan: Jika log ini aktif, status On Duty Anda juga akan direset.')">
                                                    <input type="hidden" name="action" value="delete_duty_log">
                                                    <input type="hidden" name="duty_log_id" value="<?= $activity['id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                                </form>
                                                <?php else: ?>
                                                    - 
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animasi untuk card (jika ada)
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });

            // Animasi untuk baris tabel
            const tableRows = document.querySelectorAll('.activities-table-improved tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${(cards.length * 0.1) + (index * 0.05)}s`;
                row.classList.add('fade-in');
            });

            // Animasi untuk summary cards
            const summaryCards = document.querySelectorAll('.summary-card');
            summaryCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });
        });
    </script>
</body>
</html>