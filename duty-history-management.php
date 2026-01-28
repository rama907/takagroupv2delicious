<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;
$selected_employee_id = null;
$employee_duty_logs = [];
$selected_employee_name = 'Pilih Anggota';

// Pastikan fungsi formatDuration ada
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m";
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

// Handle delete duty log (multiple or single)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_duty_logs') {
    $duty_log_ids = $_POST['duty_log_ids'] ?? [];
    $employee_id_of_log = (int)($_POST['employee_id_of_log'] ?? 0);

    if ($employee_id_of_log <= 0) {
        $error = "ID anggota tidak valid!";
    } elseif (empty($duty_log_ids)) {
        $error = "Tidak ada log jam kerja yang dipilih untuk dihapus.";
    } else {
        $conn->begin_transaction();
        try {
            $deleted_count = 0;
            $deleted_logs = [];

            $placeholders = implode(',', array_fill(0, count($duty_log_ids), '?'));
            $types = str_repeat('i', count($duty_log_ids)) . 'i'; 
            $params = $duty_log_ids;
            $params[] = $employee_id_of_log;

            // Ambil detail log sebelum dihapus untuk notifikasi
            $stmt_get_logs = $conn->prepare("
                SELECT dl.*, e.name as employee_name
                FROM duty_logs dl
                JOIN employees e ON dl.employee_id = e.id
                WHERE dl.id IN ($placeholders) AND dl.employee_id = ?
            ");
            if (!$stmt_get_logs) {
                throw new Exception("Gagal menyiapkan query ambil detail log: " . $conn->error);
            }
            $stmt_get_logs->bind_param($types, ...$params); 
            $stmt_get_logs->execute();
            $result = $stmt_get_logs->get_result();
            while ($row = $result->fetch_assoc()) {
                $deleted_logs[] = $row;
            }
            $stmt_get_logs->close();

            // Hapus log duty
            $delete_stmt_sql = "DELETE FROM duty_logs WHERE id IN ($placeholders) AND employee_id = ?";
            $stmt_delete = $conn->prepare($delete_stmt_sql);
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus log duty: " . $conn->error);
            }
            $stmt_delete->bind_param($types, ...$params);
            
            if ($stmt_delete->execute()) {
                $deleted_count = $stmt_delete->affected_rows;
                $conn->commit();
                $success = "Berhasil menghapus {$deleted_count} log jam kerja untuk **" . htmlspecialchars($deleted_logs[0]['employee_name']) . "**. Saran: Informasikan anggota untuk menginput ulang jam kerja ini dengan `Input Manual` jika diperlukan.";

                foreach ($deleted_logs as $log_details) {
                    sendDiscordNotification([
                        'employee_name' => $log_details['employee_name'],
                        'admin_name' => $user['name'],
                        'duty_start' => $log_details['duty_start'],
                        'duty_end' => $log_details['duty_end'],
                        'duration_minutes' => $log_details['duration_minutes']
                    ], 'duty_log_deleted');
                }

            } else {
                throw new Exception("Gagal menghapus log duty. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Get all active employees for the dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// BARU: Query untuk mendapatkan ID karyawan yang memiliki log durasi > 7 jam
$long_duty_employees_ids = [];
$stmt_long_duty = $conn->query("SELECT DISTINCT employee_id FROM duty_logs WHERE duration_minutes > 420");
if ($stmt_long_duty) {
    while ($row = $stmt_long_duty->fetch_assoc()) {
        $long_duty_employees_ids[] = $row['employee_id'];
    }
    $stmt_long_duty->close();
}


// Handle employee selection
if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
    $selected_employee_id = (int)$_GET['employee_id'];

    // Get name of selected employee
    foreach ($all_employees as $emp) {
        if ($emp['id'] === $selected_employee_id) {
            $selected_employee_name = htmlspecialchars($emp['name']);
            break;
        }
    }

    // Fetch duty logs for the selected employee
    $stmt_logs = $conn->prepare("
        SELECT dl.*, a.name as approved_by_name
        FROM duty_logs dl
        LEFT JOIN employees a ON dl.approved_by = a.id
        WHERE dl.employee_id = ?
        ORDER BY dl.duty_start DESC
    ");
    if ($stmt_logs) {
        $stmt_logs->bind_param("i", $selected_employee_id);
        $stmt_logs->execute();
        $employee_duty_logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_logs->close();
    } else {
        $error = "Gagal mengambil log duty: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Riwayat Jam Kerja - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .select-all-checkbox {
            margin: 0;
            padding: 0;
            vertical-align: middle;
        }
        .table-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
            gap: 1rem;
        }
        @media (max-width: 768px) {
            .table-actions {
                flex-direction: column;
            }
            .table-actions .btn {
                width: 100%;
            }
        }
        /* Gaya untuk baris tabel yang durasi kerjanya panjang */
        .long-duty-row {
            background-color: var(--warning-light) !important;
        }
        .long-duty-alert {
            font-weight: bold;
            color: var(--warning-color);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        /* Gaya badge tipe */
        .status-badge.status-primary { background-color: var(--primary-light); color: var(--primary-color); }
        .status-badge.status-info { background-color: var(--info-light); color: var(--info-color); }
        .status-badge.status-warning { background-color: var(--warning-light); color: var(--warning-color); }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">⏱️</span>
                    Manajemen Riwayat Jam Kerja
                </h1>
                <p>Lihat dan kelola riwayat jam kerja (duty log) masing-masing anggota</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= $error ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3>Pilih Anggota</h3>
                </div>
                <div class="card-content">
                    <form method="GET" class="form-group">
                        <label for="employee_select">Pilih Anggota:</label>
                        <select name="employee_id" id="employee_select" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Pilih Anggota --</option>
                            <?php foreach ($all_employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= ($selected_employee_id === $emp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                    <?php if (in_array($emp['id'], $long_duty_employees_ids)): ?>
                                        &nbsp;!
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <div class="info-message" style="margin-top: var(--spacing-lg);">
                        <strong>💡 Info:</strong> Tanda seru `!` di sebelah nama menunjukkan adanya sesi kerja tunggal yang melebihi **7 jam**.
                    </div>
                </div>
            </div>

            <?php if ($selected_employee_id): ?>
                <div class="card" style="margin-top: var(--spacing-xl);">
                    <div class="card-header">
                        <h3>Riwayat Jam Kerja: <?= $selected_employee_name ?></h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($employee_duty_logs)): ?>
                            <div class="no-data">Belum ada riwayat jam kerja untuk anggota ini.</div>
                        <?php else: ?>
                            <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                                <strong>💡 Info:</strong> Baris dengan latar belakang kuning mengindikasikan jam kerja per absensi yang melebihi **7 jam**.
                            </div>
                            <form method="POST" id="delete-multiple-form">
                                <input type="hidden" name="action" value="delete_duty_logs">
                                <input type="hidden" name="employee_id_of_log" value="<?= $selected_employee_id ?>">
                                <div class="responsive-table-container">
                                    <table class="activities-table-improved">
                                        <thead>
                                            <tr>
                                                <th><input type="checkbox" id="select-all-checkbox" class="select-all-checkbox"></th>
                                                <th>Tanggal</th>
                                                <th>Mulai</th>
                                                <th>Selesai</th>
                                                <th>Durasi</th>
                                                <th>Tipe</th>
                                                <th>Status</th>
                                                <th>Disetujui Oleh</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($employee_duty_logs as $log): ?>
                                            <?php
                                            // Perhitungan durasi & long duty
                                            $is_long_duty = ($log['duration_minutes'] > 420);
                                            $is_active = $log['status'] === 'active';

                                            // Tentukan TIPE tampilan
                                            $display_type = 'Otomatis';
                                            $status_class = 'info';
                                            if ($log['is_manual'] == 1) {
                                                $display_type = 'Manual (Web)';
                                                $status_class = 'warning';
                                            } elseif ($log['is_manual'] == 2) {
                                                $display_type = 'Discord/Bot';
                                                $status_class = 'primary';
                                            }

                                            // Tentukan Durasi & Selesai
                                            $display_duration = $is_active ? 'Berlangsung' : formatDuration($log['duration_minutes']);
                                            $display_end_time = $log['duty_end'] ? date('H:i', strtotime($log['duty_end'])) : 'Berlangsung';
                                            $display_status = ucfirst($log['status']);
                                            ?>
                                            <tr class="<?= $is_long_duty ? 'long-duty-row' : '' ?>">
                                                <td><input type="checkbox" name="duty_log_ids[]" value="<?= $log['id'] ?>" class="row-checkbox"></td>
                                                <td data-label="Tanggal"><?= date('d/m/Y', strtotime($log['duty_start'])) ?></td>
                                                <td data-label="Mulai"><?= date('H:i', strtotime($log['duty_start'])) ?></td>
                                                <td data-label="Selesai"><?= $display_end_time ?></td>
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
                                                    <span class="status-badge status-<?= $log['status'] ?>">
                                                        <?= $display_status ?>
                                                    </span>
                                                </td>
                                                <td data-label="Disetujui Oleh">
                                                    <?= $log['approved_by_name'] ? htmlspecialchars($log['approved_by_name']) : '-' ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="table-actions">
                                    <button type="submit" class="btn btn-danger" id="delete-selected-btn" disabled
                                        onclick="return confirm('Yakin ingin menghapus semua log jam kerja yang dipilih? Aksi ini TIDAK DAPAT DIBATALKAN dan notifikasi akan dikirim ke Discord.')">
                                        Hapus yang Dipilih
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all-checkbox');
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            const deleteSelectedBtn = document.getElementById('delete-selected-btn');

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    rowCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateDeleteButtonState();
                });
            }

            rowCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateDeleteButtonState();
                });
            });

            function updateDeleteButtonState() {
                const anyChecked = Array.from(rowCheckboxes).some(checkbox => checkbox.checked);
                if (deleteSelectedBtn) {
                    deleteSelectedBtn.disabled = !anyChecked;
                }
            }
        });
    </script>
</body>
</html>